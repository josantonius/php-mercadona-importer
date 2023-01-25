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

use GuzzleHttp\Client;
use Josantonius\CliPrinter\CliPrinter;

class MercadonaApi
{
    protected Client $client;

    protected int $doneRequests = 0;

    protected string $url = 'https://tienda.mercadona.es/api/';

    public function __construct(
        protected CliPrinter $printer,
        protected int $delay,
        protected string $warehouse
    ) {
        $this->client = new Client(['base_uri' => $this->url]);
    }

    public function getAvailableCategories(): array
    {
        $categories = [];

        $results = $this->getRequest('categories/')['results'] ?? [];

        foreach ($results as $result) {
            foreach ($result['categories'] ?? [] as $category) {
                if (isset($category['id'])) {
                    $categories[(int) $category['id']] = [];
                }
            }
        }

        $this->printer->api('categories.available', [count($categories)]);

        return $categories;
    }

    public function getDoneRequests(): int
    {
        return $this->doneRequests;
    }

    public function getProductDetails(string $productId): array
    {
        $product = $this->getRequest("products/$productId/");

        $this->printer->api('product.available', [$productId]);

        return $product;
    }

    public function getCategoryProducts(int $categoryId): array
    {
        $products = [];

        $categories = $this->getRequest("categories/$categoryId/")['categories'] ?? [];

        foreach ($categories as $category) {
            $products = array_merge($products, $category['products'] ?? []);
        }

        $this->printer->api('category.products.available', [count($products), $categoryId]);

        return $products;
    }

    public function setWarehouse(string $warehouse): void
    {
        $this->warehouse = $warehouse;
    }

    protected function getRequest(string $uri): array
    {
        usleep($this->delay);

        $uri .= $this->warehouse ? '?wh=' . $this->warehouse : '';

        $this->printer->api($this->url . $uri);

        $result = json_decode($this->client->request('GET', $uri)->getBody()->getContents(), true);

        $this->doneRequests++;

        return $result ?? [];
    }
}
