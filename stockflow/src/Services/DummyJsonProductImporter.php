<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Services;

use Faker\Generator;
use InvalidArgumentException;
use RuntimeException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Price\lib\Price;

final class DummyJsonProductImporter
{
    private const API_URL = 'https://dummyjson.com/products';
    private const MAX_RESPONSE_BYTES = 5_000_000;

    public function __construct(
        private readonly DatabaseService $database,
        private readonly StockflowService $stockflow,
        private readonly Generator $faker
    ) {
    }

    public function import(int $count, int $variantCount, int $skip, bool $dryRun): array
    {
        if ($count < 1 || $count > 100) {
            throw new InvalidArgumentException('Product count must be between 1 and 100.');
        }
        if ($variantCount < 1 || $variantCount > 8) {
            throw new InvalidArgumentException('Variant count must be between 1 and 8.');
        }
        if ($skip < 0 || $skip > 100_000) {
            throw new InvalidArgumentException('Product offset must be between 0 and 100,000.');
        }

        $products = $this->fetchProducts($count, $skip);
        $currency = $this->stockflow->getStoreSettings()['currency'];
        $result = [
            'requested' => $count,
            'received' => count($products),
            'created' => 0,
            'skipped' => 0,
            'variants' => 0,
            'failures' => [],
            'products' => [],
        ];

        foreach ($products as $source) {
            $product = $this->normalizeProduct($source);
            $variants = $this->generateVariants($product, $variantCount, $currency);
            $slug = $this->slug($product['name'] . '-' . $product['source_id']);
            $existing = $this->database->table('products', 'stockflow')
                ->where('slug', '=', $slug)
                ->first();
            if ($existing !== null) {
                $result['skipped']++;
                continue;
            }
            $result['products'][] = [
                'source_id' => $product['source_id'],
                'name' => $product['name'],
                'category' => $product['category_name'],
                'variants' => count($variants),
                'image_url' => $this->placeholderImageUrl($product['name']),
            ];
            if ($dryRun) {
                continue;
            }

            try {
                $categoryId = $this->ensureCategory($product['category'], $product['category_name']);
                $firstVariant = $variants[0];
                $productId = $this->stockflow->createProduct([
                    'name' => $product['name'],
                    'slug' => $slug,
                    'description' => $product['description'],
                    'brand' => $product['brand'],
                    'status' => 'active',
                    'featured' => 'no',
                    'category_id' => (string) $categoryId,
                    'sku' => $firstVariant['sku'],
                    'barcode' => $firstVariant['barcode'],
                    'option_label' => $firstVariant['option_label'],
                    'price' => $firstVariant['price']->format(false),
                    'cost_price' => $firstVariant['cost_price']->format(false),
                    'compare_at_price' => $firstVariant['compare_at_price']->format(false),
                    'weight_grams' => (string) $firstVariant['weight_grams'],
                    'quantity' => (string) $firstVariant['quantity'],
                    'reorder_point' => (string) $firstVariant['reorder_point'],
                ]);
                $createdVariantCount = 1;
                foreach (array_slice($variants, 1) as $variant) {
                    $this->stockflow->createVariant([
                        'product_id' => (string) $productId,
                        'sku' => $variant['sku'],
                        'barcode' => $variant['barcode'],
                        'option_label' => $variant['option_label'],
                        'price' => $variant['price']->format(false),
                        'cost_price' => $variant['cost_price']->format(false),
                        'compare_at_price' => $variant['compare_at_price']->format(false),
                        'weight_grams' => (string) $variant['weight_grams'],
                        'quantity' => (string) $variant['quantity'],
                        'reorder_point' => (string) $variant['reorder_point'],
                    ]);
                    $createdVariantCount++;
                }
                $this->database->table('product_images', 'stockflow')->insert([
                    'product_id' => $productId,
                    'image_url' => $this->placeholderImageUrl($product['name']),
                    'alt_text' => $product['name'],
                ]);
                $result['created']++;
                $result['variants'] += $createdVariantCount;
            } catch (\Throwable $exception) {
                $result['failures'][] = [
                    'source_id' => $product['source_id'],
                    'name' => $product['name'],
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $result;
    }

    public function fetchProducts(int $count, int $skip = 0): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP cURL extension is required to fetch DummyJSON products.');
        }
        $url = self::API_URL . '?' . http_build_query([
            'limit' => $count,
            'skip' => $skip,
            'select' => 'id,title,description,category,price,brand',
        ]);
        $body = '';
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('DummyJSON request could not be initialized.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Pindrop Stockflow DummyJSON importer/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) <= self::MAX_RESPONSE_BYTES ? strlen($chunk) : 0;
            },
        ]);
        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($success === false) {
            throw new RuntimeException('DummyJSON request failed: ' . ($error !== '' ? $error : 'response was too large.'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('DummyJSON returned HTTP ' . $status . '.');
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('DummyJSON returned invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded) || !is_array($decoded['products'] ?? null)) {
            throw new RuntimeException('DummyJSON returned an unexpected products response.');
        }
        foreach ($decoded['products'] as $product) {
            if (!is_array($product)) {
                throw new RuntimeException('DummyJSON returned an invalid product record.');
            }
        }
        return $decoded['products'];
    }

    private function normalizeProduct(array $source): array
    {
        $id = filter_var($source['id'] ?? null, FILTER_VALIDATE_INT);
        $name = $source['title'] ?? null;
        $price = $source['price'] ?? null;
        if (
            $id === false
            || $id < 1
            || !is_string($name)
            || trim($name) === ''
            || !is_numeric($price)
            || !is_finite((float) $price)
            || (float) $price <= 0
        ) {
            throw new RuntimeException('DummyJSON returned a product with missing or invalid required fields.');
        }
        $category = is_string($source['category'] ?? null) && trim($source['category']) !== ''
            ? trim($source['category'])
            : 'uncategorized';
        return [
            'source_id' => $id,
            'name' => trim($name),
            'description' => is_string($source['description'] ?? null) ? trim($source['description']) : '',
            'brand' => is_string($source['brand'] ?? null) && trim($source['brand']) !== ''
                ? trim($source['brand'])
                : $this->faker->company(),
            'category' => $category,
            'category_name' => ucwords(str_replace(['-', '_'], ' ', $category)),
            'price' => (float) $price,
        ];
    }

    private function generateVariants(array $product, int $count, string $currency): array
    {
        $variants = [];
        $usedLabels = [];
        $basePrice = new Price($product['price'], $currency);
        for ($index = 1; $index <= $count; $index++) {
            do {
                $label = $this->faker->colorName() . ' / ' . $this->faker->randomElement(['Small', 'Medium', 'Large', 'XL']);
            } while (isset($usedLabels[$label]));
            $usedLabels[$label] = true;

            $price = new Price($basePrice->getAmount(), $currency);
            $price->multiply($this->faker->randomFloat(3, 0.85, 1.2));
            $costPrice = new Price($price->getAmount(), $currency);
            $costPrice->multiply($this->faker->randomFloat(3, 0.45, 0.75));
            $compareAtPrice = new Price($price->getAmount(), $currency);
            $compareAtPrice->multiply($this->faker->randomFloat(3, 1.05, 1.3));
            $variants[] = [
                'sku' => sprintf('DJ-%04d-%02d-%s', $product['source_id'], $index, strtoupper($this->faker->bothify('??##'))),
                'barcode' => $this->faker->unique()->ean13(),
                'option_label' => $label,
                'price' => $price,
                'cost_price' => $costPrice,
                'compare_at_price' => $compareAtPrice,
                'weight_grams' => $this->faker->numberBetween(50, 5000),
                'quantity' => $this->faker->numberBetween(0, 80),
                'reorder_point' => $this->faker->numberBetween(3, 15),
            ];
        }
        return $variants;
    }

    private function ensureCategory(string $slug, string $name): int
    {
        $existing = $this->database->table('categories', 'stockflow')
            ->where('slug', '=', $this->slug($slug))
            ->first();
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        return $this->stockflow->createCategory([
            'category_name' => $name,
            'category_slug' => $slug,
            'category_description' => 'Imported sample category from DummyJSON.',
        ]);
    }

    private function placeholderImageUrl(string $name): string
    {
        $text = substr($name, 0, 72);
        return 'https://dummyjson.com/image/600x600/282828/ffffff?' . http_build_query([
            'text' => $text,
            'fontSize' => 28,
        ]);
    }

    private function slug(string $value): string
    {
        $transliterated = function_exists('iconv')
            ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value)
            : false;
        $source = $transliterated === false ? $value : $transliterated;
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $source), '-'));
        if ($slug === '') {
            throw new RuntimeException('A DummyJSON product/category cannot be converted into a valid slug.');
        }
        return $slug;
    }
}
