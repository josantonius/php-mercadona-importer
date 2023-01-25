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
    private Json $eanFile;
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

    private const HTTP_TOO_MANY_REQUESTS = 429;

    public function __construct(
        private string $timezone,
        private string $warehouse,
        private string $productsDirectory,
        private string $logsDirectory,
        private int $delayForError,
        private int $delayForRequests,
        private bool $includeFullProduct,
        private bool $reimportFullProduct,
    ) {
        date_default_timezone_set($timezone);

        $this->setPrinter();

        $this->file     = (object) [];
        $this->product  = (object) [];
        $this->category = (object) [];

        $this->eanFile   = $this->file('ean_id_mapping');
        $this->cacheFile = $this->file('cache');

        !$this->eanFile->exists()   && $this->eanFile->set([]);
        !$this->cacheFile->exists() && $this->cacheFile->set([]);

        $this->startTime    = microtime(true);
        $this->mercadonaApi = new MercadonaApi($this->printer, $delayForRequests, $warehouse);

        $this->run();
        $this->showStats();

        $this->printer->newLine();
    }

    private function run(): void
    {
        $cache = $this->getCache();

        $this->printStartMessage($cache);

        if (!$cache) {
            $this->addAvailableCategoriesToCache();
            $cache = $this->getCache();
        }

        $this->processCategoryProducts($cache);
    }

    private function processCategoryProducts(array $categories): void
    {
        foreach ($categories as $categoryId => $products) {
            $this->category->id = $categoryId;

            $products = $products ?: $this->getCategoryProductsFromApi();

            if (!$products) {
                $this->printer->error('import.product.error', $categoryId);
                $this->removeCategoryFromCache($categoryId);
                continue;
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

            $this->productsReviewed++;

            $isNew = !$this->file->instance->exists();

            $this->file->contents = !$isNew ? $this->file->instance->get() : $this->getProductSkeleton();

            $isNew ? $this->createProduct() : $this->updateProduct();

            $this->removeProductFromCache();
        }
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
        if ($this->reimportFullProduct) {
            $this->importAllProductDetails();
        }

        $this->transformProductToLocalStructure();
        $this->saveContentToFile();

        $this->productsUpdated++;

        $this->printer->update('product.updated', [$this->product->id, $this->file->instance->filepath]);
    }

    private function saveContentToFile(): void
    {
        $this->file->contents['stats']['updated_at'] = time();
        $this->file->contents['stats']['updates']++;

        ksort($this->file->contents['product']);

        $this->file->instance->set($this->file->contents);
    }

    private function importAllProductDetails(): bool
    {
        $this->product->data = $this->getProductDetailsFromApi();
        if (!$this->product->data) {
            $this->printer->error('import.product.error', $this->product->id);
            return false;
        }
        $this->eanFile->merge(['EAN' . $this->product->data['ean'] => $this->product->id]);

        return true;
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

        $logger->pushHandler(new StreamHandler($this->logsDirectory . date('Y-m-d') . '.log'));

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

    private function printStartMessage(array $cache): void
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
        $baseDirectory = '/' . trim($this->productsDirectory, '/') . '/';

        switch ($name) {
            case 'cache':
                return new Json(__DIR__ . '/data/cache.json');
            case 'ean_id_mapping':
                return new Json(__DIR__ . '/data/ean_id_mapping.json');
            case 'messages':
                return new Json(__DIR__ . '/data/messages.json');
            case 'product':
                $folder = $this->warehouse ? $this->warehouse . '/' : '';
                return new Json($baseDirectory . $folder . $this->product->id . '.json');
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
        $this->run();
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

    private function getCache(): array
    {
        return $this->cacheFile->get()[$this->warehouse] ?? [];
    }

    private function addAvailableCategoriesToCache(): void
    {
        $this->cacheFile->set([$this->warehouse => $this->getAvailableCategoriesFromApi()]);
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
