<?php

declare(strict_types=1);

/*
 * This file is part of https://github.com/josantonius/php-mercadona-importer repository.
 *
 * (c) Josantonius <hello@josantonius.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Josantonius\MercadonaImporter;

use Throwable;
use Monolog\Logger;
use Josantonius\Json\Json;
use Josantonius\CliPrinter\Color;
use Monolog\Handler\StreamHandler;
use Josantonius\CliPrinter\CliPrinter;

class MercadonaImporter
{
    private Json $productMapping;
    private Json $cacheFile;

    private object $file;
    private object $category;
    private object $product;

    private float $startTime;

    private CliPrinter $printer;

    private MercadonaApi $mercadonaApi;

    private int $productsCreated  = 0;
    private int $productsUpdated  = 0;
    private int $productsReviewed = 0;

    private string $defaultWarehouse = 'svq1';

    private const HTTP_TOO_MANY_REQUESTS = 429;

    public function __construct(
        private string $timezone,
        private string $warehouse,
        private string $outputDirectory,
        private string $logDirectory,
        private int $delayForError,
        private int $delayForRequests,
        private bool $includeFullProduct,
        private bool $reimportFullProduct,
    ) {
        date_default_timezone_set($timezone);

        $this->setPrinter();

        $this->warehouse = strtolower($this->warehouse ?: $this->defaultWarehouse);

        if (!is_dir($outputDirectory) || !is_dir($logDirectory)) {
            $this->printer->error('directory.error');
            $this->printer->error('import.cancel', $this->warehouse);
            $this->printEndMessages();
            return;
        }

        $this->file     = (object) [];
        $this->product  = (object) [];
        $this->category = (object) [];

        $this->cacheFile      = $this->file('cache');
        $this->productMapping = $this->file('ean_id_mapping');

        !$this->productMapping->exists()   && $this->productMapping->set([]);
        !$this->cacheFile->exists() && $this->cacheFile->set([]);

        $this->startTime    = microtime(true);
        $this->mercadonaApi = new MercadonaApi($this->printer, $delayForRequests, $this->warehouse);

        $this->run();
    }

    private function run(): void
    {
        $cache = $this->getCache();

        $this->printStartMessages($cache);

        if (!$cache) {
            $this->addAvailableCategoriesToCache();
            $cache = $this->getCache();
        }

        $this->processCategoryProducts($cache);
        $this->printEndMessages();
    }

    private function processCategoryProducts(array $categories): void
    {
        foreach ($categories as $categoryId => $products) {
            $this->category->id = $categoryId;

            $products = $products ?: $this->getCategoryProductsFromApi();

            if (!$products) {
                return;
            }

            $this->addProductsToCachedCategory($products);
            $this->processProducts($products);
            $this->removeCategoryFromCache();
        }
    }

    private function processProducts(array $products): void
    {
        foreach ($products as $remoteProductKey => $remoteProduct) {
            $this->product->id    = (string) $remoteProduct['id'];
            $this->product->key   = $remoteProductKey;
            $this->product->data  = $remoteProduct;
            $this->file->instance = $this->file('product');

            $isNew = !$this->file->instance->exists();

            $this->file->contents = !$isNew ? $this->file->instance->get() : $this->getProductSkeleton();

            $isNew ? $this->createProduct() : $this->updateProduct();

            $this->mapProduct();
            $this->removeProductFromCache();

            $this->productsReviewed++;
        }
    }

    private function mapProduct(): void
    {
        $key      = $this->getProductKeyByIdValue($this->product->id);
        $products = $this->productMapping->get();

        $data = [
            'id' => $this->product->id,
            'ean' => $this->product->data['ean']
                ?? $this->file->contents['product']['ean']['value']
                ?? null,
            'slug' => $this->product->data['slug']
                ?? $this->file->contents['product']['slug']['value']
                ?? null,
            'name' => $this->product->data['display_name'] ?? null,
            'warehouses' => [$this->warehouse],
        ];

        if ($key !== null) {
            $data['warehouses'] = $products[$key]['warehouses'];
            if (!in_array($this->warehouse, $data['warehouses'])) {
                $data['warehouses'][] = $this->warehouse;
            }
            $products[$key] = $data;
            $this->productMapping->set($products);
            return;
        }

        $products[] = $data;
        $this->productMapping->set($products);
    }

    private function createProduct(): void
    {
        if ($this->reimportFullProduct || $this->includeFullProduct) {
            $this->importAllProductDetails();
        }

        $this->transformProductToLocalStructure();
        $this->saveContentToFile();

        $this->productsCreated++;

        $this->printer->create('product.created', [$this->product->id, $this->file->instance->filepath]);
    }

    private function updateProduct(): void
    {
        $hasEan = isset($this->file->contents['product']['ean']['value']);

        if ($this->reimportFullProduct || (!$hasEan && $this->includeFullProduct)) {
            $this->importAllProductDetails();
        }

        $this->transformProductToLocalStructure();
        $this->saveContentToFile();

        $this->productsUpdated++;

        $this->printer->update('product.updated', [$this->product->id, $this->file->instance->filepath]);
    }

    private function importAllProductDetails(): bool
    {
        $this->product->data = $this->getProductDetailsFromApi();
        if (!$this->product->data) {
            $this->printer->error('import.product.error', $this->product->id);
            return false;
        }

        return true;
    }

    private function saveContentToFile(): void
    {
        $this->file->contents['stats']['updated_at'] = time();
        $this->file->contents['stats']['updates']++;

        ksort($this->file->contents['product']);

        $this->file->instance->set($this->file->contents);
    }

    private function transformProductToLocalStructure(): void
    {
        $this->indexCategories();

        $this->transform($this->file->contents['product'], $this->product->data);
    }

    private function indexCategories(): void
    {
        $categories = [];

        $localCategories = $this->file->contents['product']['categories'] ?? [];

        $counter = count($localCategories);
        foreach ($this->product->data['categories'] ?? [] as $category) {
            $productKey = null;
            foreach (array_column($localCategories, 'id') as $key => $item) {
                if ($item['value'] === $category['id']) {
                    $productKey = $key;
                    break;
                }
            }
            if ($productKey !== null) {
                $categories[$productKey] = $category;
                continue;
            }
            $categories[$counter] = $category;
            $counter++;
        }
        $this->product->data['categories'] = $categories;
    }

    private function transform(&$fileContents, $product, $prefix = ''): void
    {
        foreach ($product as $key => $value) {
            $path = $prefix . $key;

            if (is_array($value)) {
                $this->transform($fileContents, $value, $path . '.');
                continue;
            }
            $temp = &$fileContents;
            foreach (explode('.', $path) as $part) {
                if (!isset($temp[$part])) {
                    $temp[$part] = array();
                }
                $temp = &$temp[$part];
            }

            if (isset($temp['value']) && $temp['value'] != $value) {
                array_push($temp['previous'], [
                    'value' => $temp['value'],
                    'timestamp' => $temp['timestamp']
                ]);
                $temp['value']     = $value;
                $temp['timestamp'] = time();

                $this->printer->change('product.changed', [$path, $fileContents['id']['value']]);
                continue;
            }
            $temp['value']     = $temp['value'] ?? $value;
            $temp['previous']  = $temp['previous'] ?? [];
            $temp['timestamp'] = $temp['timestamp'] ?? time();
        }
    }

    private function getProductSkeleton(): array
    {
        return [
            'product' => [],
            'stats' => [
                'created_at' => time(),
                'updated_at' => time(),
                'updates' => 0,
            ],
        ];
    }

    private function getLogger(): Logger
    {
        $logger = new Logger('LOG');

        $logger->pushHandler(new StreamHandler($this->logDirectory . date('Y-m-d') . '.log'));

        return $logger;
    }

    private function setPrinter(): void
    {
        $messages = $this->file('messages')->get() ?? [];

        $this->printer = new CliPrinter($messages);

        $this->printer
            ->useLogger($this->getLogger())
            ->setTagColor('info', Color::BLUE)
            ->setTagColor('error', Color::RED)
            ->setTagColor('create', Color::GREEN)
            ->setTagColor('update', Color::PURPLE)
            ->setTagColor('change', Color::YELLOW)
            ->setTagColor('api', Color::CYAN);
    }

    private function printStartMessages(array $cache): void
    {
        $categoryId = array_key_first($cache);
        $productId  = array_values($cache[$categoryId] ?? [])[0]['id'] ?? null;

        $this->warehouse && $this->printer->info('warehouse.used', $this->warehouse);

        if ($categoryId === null) {
            $this->printer->info('import.start');
        } elseif ($productId) {
            $this->printer->info('import.continue.product', [$productId, $categoryId]);
        } elseif ($categoryId) {
            $this->printer->info('import.continue.category', [$categoryId]);
        }
    }

    private function printEndMessages(): void
    {
        $this->showStats();
        $this->printer->newLine();
    }

    private function showStats(): void
    {
        $this->printer->info('requests.submitted', [$this->mercadonaApi->getDoneRequests()]);
        $this->printer->info('import.stats', [
            $this->productsReviewed, $this->productsUpdated, $this->productsCreated
        ]);
        $this->printer->info('running.time', [round(microtime(true) - $this->startTime)]);
    }

    private function file(string $name): Json
    {
        $baseDirectory = '/' . trim($this->outputDirectory, '/');

        switch ($name) {
            case 'cache':
                return new Json(__DIR__ . '/data/cache.json');
            case 'ean_id_mapping':
                return new Json($baseDirectory . '/product_mapping.json');
            case 'messages':
                return new Json(__DIR__ . '/data/messages.json');
            case 'product':
                return new Json(
                    $baseDirectory . '/' . $this->warehouse . '/' . $this->product->id . '.json'
                );
        }
    }

    private function handleException(Throwable $exception): void
    {
        if ($exception->getCode() !== self::HTTP_TOO_MANY_REQUESTS) {
            $this->printer->error($exception->getMessage());
            return;
        }

        $this->printer->error('requests.exceeded');
        $this->printer->error('import.paused', [round($this->delayForError / 1000000)]);

        $this->showStats();

        usleep($this->delayForError);

        $this->processCategoryProducts($this->getCache());
    }

    private function getAvailableCategoriesFromApi(): ?array
    {
        try {
            return $this->mercadonaApi->getAvailableCategories();
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
        return null;
    }

    private function getCategoryProductsFromApi(): ?array
    {
        try {
            return $this->mercadonaApi->getCategoryProducts($this->category->id);
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
        return null;
    }

    private function getProductDetailsFromApi(): ?array
    {
        try {
            return $this->mercadonaApi->getProductDetails($this->product->id);
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
        return null;
    }

    private function getProductKeyByIdValue(string $productId): ?int
    {
        $products = $this->productMapping->get();

        foreach ($products as $key => $element) {
            if ($productId === $element['id']) {
                return $key;
            }
        }

        return null;
    }

    private function getCache(): array
    {
        return $this->cacheFile->get()[$this->warehouse] ?? [];
    }

    private function addAvailableCategoriesToCache(): void
    {
        $this->cacheFile->set($this->getAvailableCategoriesFromApi() ?? [], $this->warehouse);
    }

    private function addProductsToCachedCategory(array $products): void
    {
        $this->cacheFile->set($products, $this->warehouse . '.' . $this->category->id);
    }

    private function removeCategoryFromCache(): void
    {
        $this->cacheFile->unset($this->warehouse . '.' . $this->category->id);
    }

    private function removeProductFromCache(): void
    {
        $this->cacheFile->unset($this->warehouse . '.' . $this->category->id . '.' . $this->product->key);
    }
}
