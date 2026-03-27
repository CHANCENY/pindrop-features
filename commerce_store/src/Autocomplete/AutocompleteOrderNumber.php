<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Autocomplete;

use DI\Container;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderManager;
use Simp\Pindrop\Modules\commerce_store\src\Services\Currency;
use Simp\Pindrop\Modules\commerce_store\src\Services\Product;
use Simp\Pindrop\Modules\commerce_store\src\Services\ProductVariation;
use Simp\Pindrop\Modules\commerce_store\src\Shipment\ShipmentMethodManager;

class AutocompleteOrderNumber
{
    public function __construct(protected DatabaseService $databaseService,
                                protected OrderManager $orderManager,
                                protected Currency $currency,
                                protected ShipmentMethodManager $shipmentMethodManager,
    )
    {
    }

    public function generateOrderNumber(string $query, int $limit, ?string $sort, ?string $sort_by): array
    {
        $randomNumbers = $this->generateNumberByPlaceholder(total: $limit);

        $validated = [];
        foreach ($randomNumbers as $number) {
            if (empty($this->orderManager->order()->getOrderByNumber($number))) {
                $validated[] = [
                    'value' => $number,
                    'label' => $number
                ];
            }
        }

        return $validated;
    }

    private function generateNumberByPlaceholder(string $placeholder = "XXX-XXX-XXX", int $total = 10): array
    {
        $numbers = [];

        for ($i = 0; $i < $total; $i++) {
            $number = preg_replace_callback('/X/', function () {
                return random_int(0, 9);
            }, $placeholder);

            $numbers[] = $number;
        }

        return $numbers;
    }

    public function getProducts(string $query, int $limit, ?string $sort, ?string $sort_by)
    {
        $queryLine = "SELECT
    commerce_products.name,
    commerce_products.id,
    commerce_products.regular_price AS pro_price,
    commerce_products.sale_price AS pro_sale_price,
    commerce_products.sku AS pro_sku,
    
    commerce_product_variations.sku AS var_sku,
    commerce_product_variations.id AS var_id,
    commerce_product_variations.regular_price AS var_price,
    commerce_product_variations.sale_price AS var_sale_price,

    commerce_products.created_at
FROM commerce_products
LEFT JOIN commerce_product_variations
    ON commerce_products.id = commerce_product_variations.product_id
WHERE commerce_products.name LIKE :q1
  AND commerce_products.status = 'publish'";

       if (!empty($sort_by)) {
           $queryLine .= " ORDER BY {$sort_by} {$sort} ";
       }
       else {
           $queryLine .= " ORDER BY commerce_products.created_at DESC";
       }

       $queryLine .= " LIMIT {$limit}";

        $results = $this->databaseService->fetchAll($queryLine, ...$g = ['q1' => "%{$query}%"]);

        try {
            return $this->formatResponse($results);
        }catch (\Throwable $e) {
            return [];
        }
    }

    private function formatResponse(array $results): array
    {
        // map internal keys to human-friendly labels
        $labels = [
            'name'           => 'Name',
            'pro_sku'        => 'SKU',
            'var_sku'        => 'Variation SKU',
            'pro_price'      => 'Price',
            'pro_sale_price' => 'Sale Price',
            'var_price'      => 'Variation Price',
            'var_sale_price' => 'Variation Sale Price',
        ];

        $currency = $this->currency->getDefaultCurrency();

        return array_map(function ($result) use ($labels, $currency) {

            // extract id
            $id = $result['id'] ?? null;
            if (!empty($result['var_id'])) {
                $id = "V". $result['var_id'];
            }

            // format prices
            foreach (['pro_price', 'pro_sale_price', 'var_price', 'var_sale_price'] as $priceKey) {
                if (!empty($result[$priceKey])) {
                    $result[$priceKey] = $this->currency->format(
                        (float) $result[$priceKey],
                        $currency
                    );
                }
            }

            // remove unwanted fields
            unset($result['id'], $result['created_at']);

            // filter null / empty
            $filtered = array_filter(
                $result,
                fn ($value) => $value !== null && $value !== ''
            );

            // build readable string
            $parts = [];
            foreach ($filtered as $key => $value) {
                if (isset($labels[$key])) {
                    $parts[] = $labels[$key] . ': ' . $value;
                }
            }

            $string = implode(' | ', $parts);

            // append id
            if ($id !== null) {
                $string .= " ({$id})";
            }

            return [
                'value' => $string,
                'label' => $string,
            ];
        }, $results);
    }

    public function generateAutoCompleteString(array $item, Container $container)
    {
        /** @var Product $product */
        $product = $container->get('commerce_store.products');

        /** @var ProductVariation $productVariation */
        $productVariation = $container->get('commerce_store.product_variations');

        $productData = $product->getProduct($item['product_id']);
        if (!$productData) {
            return [];
        }

        $result = [
            'id'              => $productData['id'] ?? null,
            'name'            => $productData['name'] ?? null,
            'pro_sku'         => $productData['sku'] ?? null,
            'pro_price'       => $productData['price'] ?? null,
            'pro_sale_price'  => $productData['sale_price'] ?? null,
            'created_at'      => $productData['created_at'] ?? null,
        ];

        // Attach variation if present
        if (!empty($item['variation_id'])) {
            $variationData = $productVariation->getVariation($item['variation_id']);

            if ($variationData) {
                $result['var_id']          = $variationData['id'] ?? null;
                $result['var_sku']         = $variationData['sku'] ?? null;
                $result['var_price']       = $variationData['price'] ?? null;
                $result['var_sale_price']  = $variationData['sale_price'] ?? null;
            }
        }

        // formatResponse expects an array of results
        return $this->formatResponse([$result])[0] ?? "";
    }

    public function searchShippingMethods(string $query, int $limit, ?string $sort, ?string $sort_by): array
    {
        $shippingMethods = $this->shipmentMethodManager->getShippingMethods();

        $validated = [];

        foreach ($shippingMethods as $k=>$shippingMethod) {
            $validated[] = [
                'value' => $k . "($k)",
                'label' => $shippingMethod['name']. "($k)",
            ];
        }

        return $validated;
    }

}