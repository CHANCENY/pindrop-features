<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Services;

use InvalidArgumentException;
use RuntimeException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\FileSystem\FileSystemInterface;
use Simp\Pindrop\Settings\Settings;
use Simp\Price\lib\Currency;
use Simp\Price\lib\Price;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class StockflowService
{
    public function __construct(
        private readonly DatabaseService $database,
        private readonly Settings $settings,
        private readonly FileSystemInterface $fileSystem
    ) {
    }

    public function getDashboardData(): array
    {
        $inventory = $this->getInventory();
        $currency = $this->getCurrency();
        $stockValue = new Price(0.0, $currency);
        $units = 0;
        $lowStock = 0;

        foreach ($inventory as $item) {
            $onHand = (int) $item['quantity_on_hand'];
            $units += $onHand;
            if ($onHand <= (int) $item['reorder_point']) {
                $lowStock++;
            }
            $stockValue->add(
                (new Price((float) $item['price'], $currency))->multiply($onHand)
            );
        }

        $openOrders = $this->database->table('orders', 'stockflow')
            ->whereIn('status', ['pending', 'confirmed', 'processing', 'shipped'])
            ->count();
        $purchaseOrders = $this->database->table('purchase_orders', 'stockflow')
            ->whereIn('status', ['submitted', 'partially_received'])
            ->count();

        $revenue = new Price(0.0, $currency);
        $sales = $this->database->table('orders', 'stockflow')
            ->select(['grand_total'])
            ->where('currency_code', '=', $currency)
            ->whereIn('status', ['confirmed', 'processing', 'shipped', 'delivered'])
            ->where('placed_at', '>=', date('Y-m-01 00:00:00'))
            ->get();
        foreach ($sales as $sale) {
            $revenue->add(new Price((float) $sale['grand_total'], $currency));
        }

        return [
            'units_on_hand' => $units,
            'low_stock_count' => $lowStock,
            'open_orders' => $openOrders,
            'open_purchase_orders' => $purchaseOrders,
            'inventory_value' => $stockValue->format(),
            'sales_total' => $revenue->format(),
            'inventory' => array_slice($inventory, 0, 6),
            'recent_orders' => array_slice($this->getOrders(), 0, 5),
            'currency' => $currency,
        ];
    }

    public function getInventory(): array
    {
        $inventory = $this->database->table('inventory', 'stockflow')
            ->select([
                'inventory.variant_id',
                'inventory.quantity_on_hand',
                'inventory.quantity_reserved',
                'inventory.reorder_point',
                'product_variants.sku',
                'product_variants.barcode',
                'product_variants.price',
                'product_variants.compare_at_price',
                'product_variants.weight_grams',
                'product_variants.option_label',
                'products.name AS product_name',
                'products.slug',
                'products.brand',
                'products.status AS product_status',
                'categories.name AS category_name',
            ])
            ->join('product_variants', 'product_variants.id', '=', 'inventory.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->orderBy('products.name')
            ->get();
        foreach ($inventory as &$item) {
            $item['display_price'] = $this->formatMoney($item['price']);
        }
        unset($item);
        return $inventory;
    }

    public function getProducts(): array
    {
        $products = $this->database->table('products', 'stockflow')
            ->select([
                'products.id',
                'products.name',
                'products.slug',
                'products.brand',
                'products.status',
                'categories.name AS category_name',
            ])
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->orderBy('products.name')
            ->get();

        $variants = $this->database->table('product_variants', 'stockflow')
            ->select([
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.price',
                'inventory.quantity_on_hand',
                'inventory.reorder_point',
            ])
            ->leftJoin('inventory', 'inventory.variant_id', '=', 'product_variants.id')
            ->orderBy('product_variants.id')
            ->get();
        $variantsByProduct = [];
        foreach ($variants as $variant) {
            $variantsByProduct[$variant['product_id']][] = $variant;
        }
        foreach ($products as &$product) {
            $productVariants = $variantsByProduct[$product['id']] ?? [];
            $product['variant_count'] = count($productVariants);
            $firstVariant = $productVariants[0] ?? null;
            $product['sku'] = $firstVariant['sku'] ?? null;
            $product['price'] = $firstVariant['price'] ?? null;
            $product['quantity_on_hand'] = array_sum(array_map(
                static fn (array $variant): int => (int) ($variant['quantity_on_hand'] ?? 0),
                $productVariants
            ));
            $product['display_price'] = $product['price'] === null ? null : $this->formatMoney($product['price']);
        }
        unset($product);
        return $products;
    }

    public function getProduct(int $productId): ?array
    {
        return $this->database->table('products', 'stockflow')
            ->select([
                'products.id',
                'products.category_id',
                'products.name',
                'products.slug',
                'products.description',
                'products.brand',
                'products.status',
                'products.featured',
            ])
            ->where('products.id', '=', $productId)
            ->first();
    }

    public function getProductVariants(int $productId): array
    {
        $variants = $this->database->table('product_variants', 'stockflow')
            ->select([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.barcode',
                'product_variants.option_label',
                'product_variants.price',
                'product_variants.cost_price',
                'product_variants.compare_at_price',
                'product_variants.weight_grams',
                'product_variants.status',
                'inventory.quantity_on_hand',
                'inventory.reorder_point',
            ])
            ->leftJoin('inventory', 'inventory.variant_id', '=', 'product_variants.id')
            ->where('product_variants.product_id', '=', $productId)
            ->orderBy('product_variants.id')
            ->get();
        foreach ($variants as &$variant) {
            $variant['display_price'] = $this->formatMoney($variant['price']);
        }
        unset($variant);
        return $variants;
    }

    public function getVariant(int $variantId): ?array
    {
        return $this->database->table('product_variants', 'stockflow')
            ->select([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.barcode',
                'product_variants.option_label',
                'product_variants.price',
                'product_variants.cost_price',
                'product_variants.compare_at_price',
                'product_variants.weight_grams',
                'product_variants.status',
                'inventory.reorder_point',
                'products.name AS product_name',
            ])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('inventory', 'inventory.variant_id', '=', 'product_variants.id')
            ->where('product_variants.id', '=', $variantId)
            ->first();
    }

    public function getVariantForApi(int $variantId): ?array
    {
        return $this->variantApiQuery()
            ->where('product_variants.id', '=', $variantId)
            ->first();
    }

    public function getVariantsForApiCode(string $code): array
    {
        $bySku = $this->variantApiQuery()
            ->where('product_variants.sku', '=', $code)
            ->get();
        $bySku = array_values(array_filter(
            $bySku,
            static fn (array $variant): bool => hash_equals((string) $variant['sku'], $code)
        ));
        if ($bySku !== []) {
            return $bySku;
        }
        $byBarcode = $this->variantApiQuery()
            ->where('product_variants.barcode', '=', $code)
            ->get();
        return array_values(array_filter(
            $byBarcode,
            static fn (array $variant): bool => hash_equals((string) ($variant['barcode'] ?? ''), $code)
        ));
    }

    private function variantApiQuery(): \Simp\Pindrop\Database\QueryBuilder
    {
        return $this->database->table('product_variants', 'stockflow')
            ->select([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.barcode',
                'product_variants.option_label',
                'product_variants.price',
                'product_variants.compare_at_price',
                'product_variants.weight_grams',
                'product_variants.status AS variant_status',
                'inventory.quantity_on_hand',
                'inventory.quantity_reserved',
                'products.name AS product_name',
                'products.slug AS product_slug',
                'products.brand',
                'products.description',
                'products.status AS product_status',
                'categories.name AS category_name',
            ])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('inventory', 'inventory.variant_id', '=', 'product_variants.id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id');
    }

    public function getCategories(): array
    {
        return $this->database->table('categories', 'stockflow')
            ->select(['id', 'name'])
            ->where('is_active', '=', 1)
            ->orderBy('sort_order')
            ->get();
    }

    public function getVariants(): array
    {
        return $this->database->table('product_variants', 'stockflow')
            ->select([
                'product_variants.id',
                'product_variants.sku',
                'product_variants.option_label',
                'products.name AS product_name',
            ])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_variants.status', '=', 'active')
            ->orderBy('products.name')
            ->get();
    }

    public function getOrders(array $filters = [], int $limit = 100): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new InvalidArgumentException('Order result limit must be between 1 and 10,000.');
        }
        $query = $this->database->table('orders', 'stockflow')
            ->select([
                'id',
                'order_number',
                'email',
                'status',
                'currency_code',
                'subtotal',
                'discount_total',
                'shipping_total',
                'tax_total',
                'grand_total',
                'order_adjustments',
                'placed_at',
            ]);

        $status = $this->inputString($filters['status'] ?? '', 'Order status');
        if ($status !== '') {
            $query->where('status', '=', $this->choice($status, [
                'pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded',
            ], 'order status'));
        }
        $email = $this->inputString($filters['email'] ?? '', 'Customer email');
        if ($email !== '') {
            $query->where('email', 'LIKE', '%' . $email . '%');
        }
        $from = $this->inputString($filters['from'] ?? '', 'Start date');
        if ($from !== '') {
            if (!$this->isDate($from)) {
                throw new InvalidArgumentException('Enter a valid start date.');
            }
            $query->where('placed_at', '>=', $from . ' 00:00:00');
        }
        $to = $this->inputString($filters['to'] ?? '', 'End date');
        if ($to !== '') {
            if (!$this->isDate($to)) {
                throw new InvalidArgumentException('Enter a valid end date.');
            }
            $query->where('placed_at', '<=', $to . ' 23:59:59');
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new InvalidArgumentException('Start date must not be after end date.');
        }

        $orders = $query->orderBy('placed_at', 'DESC')->limit($limit)->get();
        foreach ($orders as &$order) {
            $order['display_total'] = $this->formatOrderTotal($order);
            $payment = $this->database->table('payments', 'stockflow')
                ->select(['provider', 'status'])
                ->where('order_id', '=', $order['id'])
                ->orderBy('created_at', 'DESC')
                ->first();
            $order['payment_provider'] = $payment['provider'] ?? null;
            $order['payment_status'] = $payment['status'] ?? null;
        }
        unset($order);
        return $orders;
    }

    public function getCustomers(): array
    {
        return $this->database->table('customers', 'stockflow')
            ->select(['id', 'email', 'first_name', 'last_name', 'phone', 'status', 'created_at'])
            ->orderBy('created_at', 'DESC')
            ->limit(100)
            ->get();
    }

    public function getSuppliers(bool $activeOnly = true): array
    {
        $query = $this->database->table('suppliers', 'stockflow')
            ->select(['id', 'code', 'name', 'email', 'phone', 'website', 'payment_terms', 'status'])
            ->orderBy('name');
        if ($activeOnly) {
            $query->where('status', '=', 'active');
        }
        return $query->get();
    }

    public function getSupplier(int $supplierId): ?array
    {
        return $this->database->table('suppliers', 'stockflow')
            ->select(['id', 'code', 'name', 'email', 'phone', 'website', 'payment_terms', 'status'])
            ->where('id', '=', $supplierId)
            ->first();
    }

    public function getPurchaseOrders(): array
    {
        $orders = $this->database->table('purchase_orders', 'stockflow')
            ->select([
                'purchase_orders.id',
                'purchase_orders.order_number',
                'purchase_orders.status',
                'purchase_orders.expected_at',
                'purchase_orders.subtotal',
                'purchase_orders.created_at',
                'suppliers.code AS supplier_code',
                'suppliers.name AS supplier_name',
                'suppliers.email AS supplier_email',
                'suppliers.phone AS supplier_phone',
            ])
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->orderBy('purchase_orders.created_at', 'DESC')
            ->limit(100)
            ->get();

        foreach ($orders as &$order) {
            $order['display_subtotal'] = $this->formatMoney($order['subtotal']);
            $items = $this->database->table('purchase_order_items', 'stockflow')
                ->select(['quantity_ordered', 'quantity_received'])
                ->where('purchase_order_id', '=', $order['id'])
                ->get();
            $order['receivable_quantity'] = 0;
            $order['received_quantity'] = 0;
            foreach ($items as $item) {
                $order['receivable_quantity'] += (int) $item['quantity_ordered'] - (int) $item['quantity_received'];
                $order['received_quantity'] += (int) $item['quantity_received'];
            }
        }
        unset($order);
        return $orders;
    }

    public function getPurchaseOrder(int $purchaseOrderId): ?array
    {
        $order = $this->database->table('purchase_orders', 'stockflow')
            ->select([
                'purchase_orders.id',
                'purchase_orders.order_number',
                'purchase_orders.status',
                'purchase_orders.expected_at',
                'purchase_orders.subtotal',
                'purchase_orders.notes',
                'purchase_orders.supplier_id',
                'purchase_orders.created_at',
                'suppliers.code AS supplier_code',
                'suppliers.name AS supplier_name',
                'suppliers.email AS supplier_email',
                'suppliers.phone AS supplier_phone',
            ])
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->where('purchase_orders.id', '=', $purchaseOrderId)
            ->first();
        if ($order === null) {
            return null;
        }

        $items = $this->database->table('purchase_order_items', 'stockflow')
            ->select([
                'purchase_order_items.variant_id',
                'purchase_order_items.quantity_ordered',
                'purchase_order_items.quantity_received',
                'purchase_order_items.unit_cost',
                'product_variants.sku',
                'product_variants.option_label',
                'products.name AS product_name',
            ])
            ->join('product_variants', 'product_variants.id', '=', 'purchase_order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('purchase_order_items.purchase_order_id', '=', $purchaseOrderId)
            ->get();
        $order['items'] = $items;
        $order['receivable_quantity'] = 0;
        foreach ($order['items'] as &$item) {
            $item['display_unit_cost'] = $this->formatMoney($item['unit_cost']);
            $order['receivable_quantity'] += (int) $item['quantity_ordered'] - (int) $item['quantity_received'];
        }
        unset($item);
        $order['display_subtotal'] = $this->formatMoney($order['subtotal']);
        return $order;
    }

    public function createProduct(array $data, ?UploadedFile $image = null): int
    {
        $name = $this->requiredString($data, 'name', 'Product name');
        $slugInput = $this->inputString($data['slug'] ?? '', 'URL slug');
        $slug = $this->normalizeSlug($slugInput !== '' ? $slugInput : $name, 'Product name');

        $sku = strtoupper($this->requiredString($data, 'sku', 'Variant SKU'));
        $price = $this->money($data['price'] ?? '', 'Variant price');
        if ($price->isLessThan(0.0)) {
            throw new InvalidArgumentException('Variant price cannot be negative.');
        }
        $costPrice = null;
        $costInput = $this->inputString($data['cost_price'] ?? '', 'Cost price');
        if ($costInput !== '') {
            $costPrice = $this->money($costInput, 'Cost price');
            if ($costPrice->isLessThan(0.0)) {
                throw new InvalidArgumentException('Cost price cannot be negative.');
            }
        }
        $compareAtPrice = null;
        $compareAtInput = $this->inputString($data['compare_at_price'] ?? '', 'Compare-at price');
        if ($compareAtInput !== '') {
            $compareAtPrice = $this->money($compareAtInput, 'Compare-at price');
            if ($compareAtPrice->isLessThan(0.0)) {
                throw new InvalidArgumentException('Compare-at price cannot be negative.');
            }
        }
        $weightInput = $this->inputString($data['weight_grams'] ?? '', 'Variant weight');
        $weightGrams = $weightInput === '' ? null : $this->integer($weightInput, 'Variant weight');
        if ($weightGrams !== null && $weightGrams < 0) {
            throw new InvalidArgumentException('Variant weight cannot be negative.');
        }

        $quantity = $this->integer($data['quantity'] ?? '0', 'Starting quantity');
        $reorderPoint = $this->integer($data['reorder_point'] ?? '0', 'Reorder point');
        if ($quantity < 0 || $reorderPoint < 0) {
            throw new InvalidArgumentException('Starting quantity and reorder point cannot be negative.');
        }

        $categoryValue = $this->inputString($data['category_id'] ?? '', 'Category');
        $categoryId = in_array($categoryValue, ['', 'new'], true)
            ? null
            : $this->integer($categoryValue, 'Category');
        $newCategoryName = $categoryId === null
            ? $this->inputString($data['category_name'] ?? '', 'Category name')
            : '';
        if ($categoryId !== null && !$this->database->table('categories', 'stockflow')->where('id', '=', $categoryId)->exists()) {
            throw new InvalidArgumentException('Selected category does not exist.');
        }
        $newCategorySlug = null;
        if ($newCategoryName !== '') {
            $categorySlugInput = $this->inputString($data['category_slug'] ?? '', 'Category slug');
            $newCategorySlug = $this->normalizeSlug(
                $categorySlugInput !== '' ? $categorySlugInput : $newCategoryName,
                'Category name'
            );
        }
        if ($image !== null && $image->getError() !== UPLOAD_ERR_NO_FILE) {
            $this->validateProductImage($image);
        }

        $uploadedUri = null;
        try {
            return $this->database->transaction(function () use (
                $data,
                $name,
                $slug,
                $sku,
                $price,
                $costPrice,
                $compareAtPrice,
                $weightGrams,
                $quantity,
                $reorderPoint,
                &$categoryId,
                $newCategoryName,
                $newCategorySlug,
                $image,
                &$uploadedUri
            ): int {
                if ($newCategoryName !== '' && $newCategorySlug !== null) {
                    $categoryId = $this->database->table('categories', 'stockflow')->insert([
                        'name' => $newCategoryName,
                        'slug' => $newCategorySlug,
                        'description' => $this->inputString($data['category_description'] ?? '', 'Category description'),
                        'is_active' => 1,
                    ]);
                }

                $productId = $this->database->table('products', 'stockflow')->insert([
                    'category_id' => $categoryId,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $this->inputString($data['description'] ?? '', 'Description'),
                    'brand' => $this->inputString($data['brand'] ?? '', 'Brand'),
                    'status' => $this->choice($data['status'] ?? 'draft', ['draft', 'active', 'archived'], 'Product status'),
                    'featured' => (int) ($this->choice($data['featured'] ?? 'no', ['no', 'yes'], 'featured setting') === 'yes'),
                ]);

                $variantId = $this->database->table('product_variants', 'stockflow')->insert([
                    'product_id' => $productId,
                    'sku' => $sku,
                    'barcode' => $this->inputString($data['barcode'] ?? '', 'Barcode') ?: null,
                    'option_label' => $this->inputString($data['option_label'] ?? '', 'Variant title') ?: null,
                    'price' => $price->getAmount(),
                    'cost_price' => $costPrice?->getAmount(),
                    'compare_at_price' => $compareAtPrice?->getAmount(),
                    'weight_grams' => $weightGrams,
                    'status' => 'active',
                ]);

                $this->database->table('inventory', 'stockflow')->insert([
                    'variant_id' => $variantId,
                    'quantity_on_hand' => $quantity,
                    'reorder_point' => $reorderPoint,
                    'reorder_quantity' => max($reorderPoint, 1),
                ]);

                if ($image !== null && $image->getError() !== UPLOAD_ERR_NO_FILE) {
                    $imageUrl = $this->uploadProductImage($image, $uploadedUri);
                    $this->database->table('product_images', 'stockflow')->insert([
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'image_url' => $imageUrl,
                        'alt_text' => $name,
                    ]);
                }

                return $productId;
            });
        } catch (\Throwable $exception) {
            if ($uploadedUri !== null) {
                $this->fileSystem->delete($uploadedUri);
            }
            throw $exception;
        }
    }

    public function updateProduct(int $productId, array $data): void
    {
        if ($this->getProduct($productId) === null) {
            throw new InvalidArgumentException('Product not found.');
        }
        $categoryValue = $this->inputString($data['category_id'] ?? '', 'Category');
        $categoryId = $categoryValue === '' ? null : $this->integer($categoryValue, 'Category');
        if ($categoryId !== null && !$this->database->table('categories', 'stockflow')->where('id', '=', $categoryId)->exists()) {
            throw new InvalidArgumentException('Selected category does not exist.');
        }
        $name = $this->requiredString($data, 'name', 'Product name');
        $slugInput = $this->inputString($data['slug'] ?? '', 'URL slug');
        $slug = $this->normalizeSlug($slugInput !== '' ? $slugInput : $name, 'Product name');
        if ($this->database->table('products', 'stockflow')
            ->where('slug', '=', $slug)
            ->where('id', '<>', $productId)
            ->exists()) {
            throw new InvalidArgumentException('Another product already uses this URL slug.');
        }

        $this->database->table('products', 'stockflow')
            ->where('id', '=', $productId)
            ->update([
                'category_id' => $categoryId,
                'name' => $name,
                'slug' => $slug,
                'description' => $this->inputString($data['description'] ?? '', 'Description'),
                'brand' => $this->inputString($data['brand'] ?? '', 'Brand'),
                'status' => $this->choice($data['status'] ?? 'draft', ['draft', 'active', 'archived'], 'Product status'),
                'featured' => (int) ($this->choice($data['featured'] ?? 'no', ['no', 'yes'], 'featured setting') === 'yes'),
            ]);
    }

    public function deleteProduct(int $productId): void
    {
        if ($this->getProduct($productId) === null) {
            throw new InvalidArgumentException('Product not found.');
        }
        $variants = $this->database->table('product_variants', 'stockflow')
            ->select(['id'])
            ->where('product_id', '=', $productId)
            ->get();
        $variantIds = array_map(static fn (array $variant): int => (int) $variant['id'], $variants);
        if ($variantIds !== [] && $this->variantHasCommittedHistory($variantIds)) {
            throw new InvalidArgumentException(
                'This product has inventory, order, purchase, or cart history and cannot be deleted.'
            );
        }

        $this->database->table('products', 'stockflow')->where('id', '=', $productId)->delete();
    }

    public function updateVariant(int $variantId, array $data): void
    {
        $variant = $this->getVariant($variantId);
        if ($variant === null) {
            throw new InvalidArgumentException('Product variant not found.');
        }
        $price = $this->money($data['price'] ?? '', 'Variant price');
        if ($price->isLessThan(0.0)) {
            throw new InvalidArgumentException('Variant price cannot be negative.');
        }
        $costPrice = null;
        $costInput = $this->inputString($data['cost_price'] ?? '', 'Cost price');
        if ($costInput !== '') {
            $costPrice = $this->money($costInput, 'Cost price');
            if ($costPrice->isLessThan(0.0)) {
                throw new InvalidArgumentException('Cost price cannot be negative.');
            }
        }
        $compareAtPrice = null;
        $compareAtInput = $this->inputString($data['compare_at_price'] ?? '', 'Compare-at price');
        if ($compareAtInput !== '') {
            $compareAtPrice = $this->money($compareAtInput, 'Compare-at price');
            if ($compareAtPrice->isLessThan(0.0)) {
                throw new InvalidArgumentException('Compare-at price cannot be negative.');
            }
        }
        $weightInput = $this->inputString($data['weight_grams'] ?? '', 'Variant weight');
        $weightGrams = $weightInput === '' ? null : $this->integer($weightInput, 'Variant weight');
        if ($weightGrams !== null && $weightGrams < 0) {
            throw new InvalidArgumentException('Variant weight cannot be negative.');
        }
        $reorderPoint = $this->integer($data['reorder_point'] ?? '0', 'Reorder point');
        if ($reorderPoint < 0) {
            throw new InvalidArgumentException('Reorder point cannot be negative.');
        }
        $sku = strtoupper($this->requiredString($data, 'sku', 'Variant SKU'));
        if ($this->database->table('product_variants', 'stockflow')
            ->where('sku', '=', $sku)
            ->where('id', '<>', $variantId)
            ->exists()) {
            throw new InvalidArgumentException('Another variant already uses this SKU.');
        }

        $this->database->transaction(function () use ($variantId, $data, $price, $costPrice, $compareAtPrice, $weightGrams, $reorderPoint, $sku): void {
            $this->database->table('product_variants', 'stockflow')
                ->where('id', '=', $variantId)
                ->update([
                    'sku' => $sku,
                    'barcode' => $this->inputString($data['barcode'] ?? '', 'Barcode') ?: null,
                    'option_label' => $this->inputString($data['option_label'] ?? '', 'Variant title') ?: null,
                    'price' => $price->getAmount(),
                    'cost_price' => $costPrice?->getAmount(),
                    'compare_at_price' => $compareAtPrice?->getAmount(),
                    'weight_grams' => $weightGrams,
                    'status' => $this->choice($data['status'] ?? 'active', ['active', 'archived'], 'Variant status'),
                ]);
            $this->database->table('inventory', 'stockflow')
                ->where('variant_id', '=', $variantId)
                ->update(['reorder_point' => $reorderPoint]);
        });
    }

    public function deleteVariant(int $variantId, int $productId): void
    {
        $variant = $this->getVariant($variantId);
        if ($variant === null || (int) $variant['product_id'] !== $productId) {
            throw new InvalidArgumentException('Product variant not found.');
        }
        $variantCount = $this->database->table('product_variants', 'stockflow')
            ->where('product_id', '=', (int) $variant['product_id'])
            ->count();
        if ($variantCount <= 1) {
            throw new InvalidArgumentException('A product must keep at least one variant. Delete the product instead.');
        }
        if ($this->variantHasCommittedHistory([$variantId])) {
            throw new InvalidArgumentException(
                'This variant has inventory, order, purchase, or cart history and cannot be deleted.'
            );
        }

        $this->database->table('product_variants', 'stockflow')->where('id', '=', $variantId)->delete();
    }

    private function variantHasCommittedHistory(array $variantIds): bool
    {
        return $this->database->table('order_items', 'stockflow')
                ->whereIn('variant_id', $variantIds)
                ->exists()
            || $this->database->table('purchase_order_items', 'stockflow')
                ->whereIn('variant_id', $variantIds)
                ->exists()
            || $this->database->table('cart_items', 'stockflow')
                ->whereIn('variant_id', $variantIds)
                ->exists()
            || $this->database->table('inventory_movements', 'stockflow')
                ->whereIn('variant_id', $variantIds)
                ->exists();
    }

    public function createCategory(array $data): int
    {
        $name = $this->requiredString($data, 'category_name', 'Category name');
        $slugInput = $this->inputString($data['category_slug'] ?? '', 'Category slug');
        $slug = $this->normalizeSlug($slugInput !== '' ? $slugInput : $name, 'Category name');

        return $this->database->table('categories', 'stockflow')->insert([
            'name' => $name,
            'slug' => $slug,
            'description' => $this->inputString($data['category_description'] ?? '', 'Category description'),
            'is_active' => 1,
        ]);
    }

    public function createVariant(array $data): int
    {
        $productId = $this->integer($data['product_id'] ?? '', 'Product');
        if (!$this->database->table('products', 'stockflow')->where('id', '=', $productId)->exists()) {
            throw new InvalidArgumentException('Selected product does not exist.');
        }
        $sku = strtoupper($this->requiredString($data, 'sku', 'Variant SKU'));
        $price = $this->money($data['price'] ?? '', 'Variant price');
        if ($price->isLessThan(0.0)) {
            throw new InvalidArgumentException('Variant price cannot be negative.');
        }

        $costPrice = null;
        $costInput = $this->inputString($data['cost_price'] ?? '', 'Cost price');
        if ($costInput !== '') {
            $costPrice = $this->money($costInput, 'Cost price');
            if ($costPrice->isLessThan(0.0)) {
                throw new InvalidArgumentException('Cost price cannot be negative.');
            }
        }
        $compareAtPrice = null;
        $compareAtInput = $this->inputString($data['compare_at_price'] ?? '', 'Compare-at price');
        if ($compareAtInput !== '') {
            $compareAtPrice = $this->money($compareAtInput, 'Compare-at price');
            if ($compareAtPrice->isLessThan(0.0)) {
                throw new InvalidArgumentException('Compare-at price cannot be negative.');
            }
        }
        $weightInput = $this->inputString($data['weight_grams'] ?? '', 'Variant weight');
        $weightGrams = $weightInput === '' ? null : $this->integer($weightInput, 'Variant weight');
        if ($weightGrams !== null && $weightGrams < 0) {
            throw new InvalidArgumentException('Variant weight cannot be negative.');
        }

        $quantity = $this->integer($data['quantity'] ?? '0', 'Starting quantity');
        $reorderPoint = $this->integer($data['reorder_point'] ?? '0', 'Reorder point');
        if ($quantity < 0 || $reorderPoint < 0) {
            throw new InvalidArgumentException('Starting quantity and reorder point cannot be negative.');
        }

        return $this->database->transaction(function () use ($data, $productId, $sku, $price, $costPrice, $compareAtPrice, $weightGrams, $quantity, $reorderPoint): int {
            $variantId = $this->database->table('product_variants', 'stockflow')->insert([
                'product_id' => $productId,
                'sku' => $sku,
                'barcode' => $this->inputString($data['barcode'] ?? '', 'Barcode') ?: null,
                'option_label' => $this->inputString($data['option_label'] ?? '', 'Variant title') ?: null,
                'price' => $price->getAmount(),
                'cost_price' => $costPrice?->getAmount(),
                'compare_at_price' => $compareAtPrice?->getAmount(),
                'weight_grams' => $weightGrams,
                'status' => 'active',
            ]);
            $this->database->table('inventory', 'stockflow')->insert([
                'variant_id' => $variantId,
                'quantity_on_hand' => $quantity,
                'reorder_point' => $reorderPoint,
                'reorder_quantity' => max($reorderPoint, 1),
            ]);
            return $variantId;
        });
    }

    public function adjustInventory(array $data): void
    {
        $variantId = $this->integer($data['variant_id'] ?? '', 'Product variant');
        $delta = $this->integer($data['quantity_delta'] ?? '', 'Quantity change');
        if ($delta === 0) {
            throw new InvalidArgumentException('Quantity change must not be zero.');
        }
        $movementType = $this->choice(
            $data['movement_type'] ?? '',
            ['receipt', 'sale', 'return', 'adjustment', 'damage'],
            'Movement type'
        );
        if (in_array($movementType, ['receipt', 'return'], true) && $delta < 0) {
            throw new InvalidArgumentException('Receipt and return movements must add stock.');
        }
        if (in_array($movementType, ['sale', 'damage'], true) && $delta > 0) {
            throw new InvalidArgumentException('Sale and damage movements must remove stock.');
        }
        $reason = $this->inputString($data['reason'] ?? '', 'Reason');
        if ($reason === '') {
            throw new InvalidArgumentException('Provide a reason for the stock movement.');
        }

        $this->database->transaction(function () use ($variantId, $delta, $movementType, $reason): void {
            $inventory = $this->database->table('inventory', 'stockflow')
                ->where('variant_id', '=', $variantId)
                ->first();
            if ($inventory === null) {
                throw new InvalidArgumentException('Inventory record not found for this variant.');
            }
            $newQuantity = (int) $inventory['quantity_on_hand'] + $delta;
            if ($newQuantity < 0) {
                throw new InvalidArgumentException('This movement would make on-hand inventory negative.');
            }

            $updated = $this->database->table('inventory', 'stockflow')
                ->where('variant_id', '=', $variantId)
                ->where('quantity_on_hand', '=', (int) $inventory['quantity_on_hand'])
                ->update(['quantity_on_hand' => $newQuantity]);
            if ($updated !== 1) {
                throw new RuntimeException('Inventory changed concurrently; retry the stock adjustment.');
            }
            $this->database->table('inventory_movements', 'stockflow')->insert([
                'variant_id' => $variantId,
                'quantity_delta' => $delta,
                'movement_type' => $movementType,
                'reason' => $reason,
            ]);
        });
    }

    public function validateCustomerData(array $data): array
    {
        $email = $this->inputString($data['email'] ?? '', 'Email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid customer email address.');
        }
        $status = $this->choice($data['status'] ?? 'active', ['active', 'guest', 'blocked'], 'Customer status');
        $acceptsMarketing = $this->choice(
            $data['accepts_marketing'] ?? 'no',
            ['no', 'yes'],
            'marketing preference'
        );
        return [
            'email' => $email,
            'first_name' => $this->requiredString($data, 'first_name', 'First name'),
            'last_name' => $this->requiredString($data, 'last_name', 'Last name'),
            'phone' => $this->inputString($data['phone'] ?? '', 'Phone') ?: null,
            'status' => $status,
            'accepts_marketing' => $acceptsMarketing,
        ];
    }

    public function createCustomer(array $data, ?int $userId = null): int
    {
        $customer = $this->validateCustomerData($data);
        $customer['accepts_marketing'] = (int) ($customer['accepts_marketing'] === 'yes');
        return $this->database->table('customers', 'stockflow')->insert([
            'user_id' => $userId,
            ...$customer,
        ]);
    }

    public function createSupplier(array $data): int
    {
        $supplier = $this->validateSupplierData($data);
        return $this->database->table('suppliers', 'stockflow')->insert([
            ...$supplier,
            'status' => 'active',
        ]);
    }

    public function updateSupplier(int $supplierId, array $data): void
    {
        if ($this->getSupplier($supplierId) === null) {
            throw new InvalidArgumentException('Supplier not found.');
        }
        $supplier = $this->validateSupplierData($data);
        $supplier['status'] = $this->choice($data['status'] ?? 'active', ['active', 'inactive'], 'supplier status');
        $this->database->table('suppliers', 'stockflow')
            ->where('id', '=', $supplierId)
            ->update($supplier);
    }

    public function validateSupplierData(array $data): array
    {
        $email = $this->inputString($data['email'] ?? '', 'Email');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid supplier email address.');
        }
        $website = $this->inputString($data['website'] ?? '', 'Website');
        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Enter a valid supplier website URL.');
        }
        return [
            'code' => strtoupper($this->requiredString($data, 'code', 'Supplier code')),
            'name' => $this->requiredString($data, 'name', 'Supplier name'),
            'email' => $email ?: null,
            'phone' => $this->inputString($data['phone'] ?? '', 'Phone') ?: null,
            'website' => $website ?: null,
            'payment_terms' => $this->inputString($data['payment_terms'] ?? '', 'Payment terms') ?: null,
        ];
    }

    public function createPurchaseOrder(array $data): int
    {
        $supplierId = $this->integer($data['supplier_id'] ?? '', 'Supplier');
        $variantId = $this->integer($data['variant_id'] ?? '', 'Product variant');
        $quantity = $this->integer($data['quantity'] ?? '', 'Quantity ordered');
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity ordered must be greater than zero.');
        }
        $unitCost = $this->money($data['unit_cost'] ?? '', 'Unit cost');
        if ($unitCost->isLessThan(0.0)) {
            throw new InvalidArgumentException('Unit cost cannot be negative.');
        }
        $expectedAt = $this->inputString($data['expected_at'] ?? '', 'Expected date');
        if ($expectedAt !== '' && !$this->isDate($expectedAt)) {
            throw new InvalidArgumentException('Enter a valid expected date.');
        }
        $supplier = $this->getSupplier($supplierId);
        if ($supplier === null || $supplier['status'] !== 'active') {
            throw new InvalidArgumentException('Selected supplier does not exist.');
        }
        if (!$this->database->table('product_variants', 'stockflow')->where('id', '=', $variantId)->exists()) {
            throw new InvalidArgumentException('Selected product variant does not exist.');
        }

        $subtotal = (new Price($unitCost->getAmount(), $this->getCurrency()))
            ->multiply($quantity);

        return $this->database->transaction(function () use (
            $data,
            $supplierId,
            $variantId,
            $quantity,
            $unitCost,
            $subtotal,
            $expectedAt
        ): int {
            $orderId = $this->database->table('purchase_orders', 'stockflow')->insert([
                'order_number' => 'PO-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
                'supplier_id' => $supplierId,
                'status' => 'submitted',
                'expected_at' => $expectedAt !== '' ? $expectedAt . ' 00:00:00' : null,
                'subtotal' => $subtotal->getAmount(),
                'notes' => $this->inputString($data['notes'] ?? '', 'Notes') ?: null,
            ]);

            $this->database->table('purchase_order_items', 'stockflow')->insert([
                'purchase_order_id' => $orderId,
                'variant_id' => $variantId,
                'quantity_ordered' => $quantity,
                'unit_cost' => $unitCost->getAmount(),
            ]);

            return $orderId;
        });
    }

    public function updatePurchaseOrder(int $purchaseOrderId, array $data): void
    {
        $purchaseOrder = $this->getPurchaseOrder($purchaseOrderId);
        if ($purchaseOrder === null) {
            throw new InvalidArgumentException('Purchase order not found.');
        }
        if ($purchaseOrder['status'] !== 'submitted'
            || array_sum(array_map(static fn (array $item): int => (int) $item['quantity_received'], $purchaseOrder['items'])) > 0) {
            throw new InvalidArgumentException('Only purchase orders with no received stock can be edited.');
        }

        $supplierId = $this->integer($data['supplier_id'] ?? '', 'Supplier');
        $supplier = $this->getSupplier($supplierId);
        if ($supplier === null || $supplier['status'] !== 'active') {
            throw new InvalidArgumentException('Select an active supplier.');
        }
        $variantId = $this->integer($data['variant_id'] ?? '', 'Product variant');
        if (!$this->database->table('product_variants', 'stockflow')
            ->where('id', '=', $variantId)
            ->where('status', '=', 'active')
            ->exists()) {
            throw new InvalidArgumentException('Select an active product variant.');
        }
        $quantity = $this->integer($data['quantity'] ?? '', 'Quantity ordered');
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity ordered must be greater than zero.');
        }
        $unitCost = $this->money($data['unit_cost'] ?? '', 'Unit cost');
        if ($unitCost->isLessThan(0.0)) {
            throw new InvalidArgumentException('Unit cost cannot be negative.');
        }
        $expectedAt = $this->inputString($data['expected_at'] ?? '', 'Expected date');
        if ($expectedAt !== '' && !$this->isDate($expectedAt)) {
            throw new InvalidArgumentException('Enter a valid expected date.');
        }
        $subtotal = (new Price($unitCost->getAmount(), $this->getCurrency()))->multiply($quantity);

        $this->database->transaction(function () use ($purchaseOrderId, $supplierId, $variantId, $quantity, $unitCost, $expectedAt, $subtotal, $data): void {
            $this->database->table('purchase_orders', 'stockflow')
                ->where('id', '=', $purchaseOrderId)
                ->where('status', '=', 'submitted')
                ->update([
                    'supplier_id' => $supplierId,
                    'expected_at' => $expectedAt !== '' ? $expectedAt . ' 00:00:00' : null,
                    'subtotal' => $subtotal->getAmount(),
                    'notes' => $this->inputString($data['notes'] ?? '', 'Notes') ?: null,
                ]);
            $items = $this->database->table('purchase_order_items', 'stockflow')
                ->where('purchase_order_id', '=', $purchaseOrderId)
                ->get();
            if (count($items) !== 1 || (int) $items[0]['quantity_received'] !== 0) {
                throw new InvalidArgumentException('This purchase order can no longer be edited.');
            }
            $this->database->table('purchase_order_items', 'stockflow')
                ->where('id', '=', $items[0]['id'])
                ->where('quantity_received', '=', 0)
                ->update([
                    'variant_id' => $variantId,
                    'quantity_ordered' => $quantity,
                    'unit_cost' => $unitCost->getAmount(),
                ]);
        });
    }

    public function receivePurchaseOrder(array $data): void
    {
        $purchaseOrderId = $this->integer($data['purchase_order_id'] ?? '', 'Purchase order');
        $quantityToReceive = $this->integer($data['quantity_received'] ?? '', 'Received quantity');
        if ($quantityToReceive <= 0) {
            throw new InvalidArgumentException('Received quantity must be greater than zero.');
        }

        $this->database->transaction(function () use ($purchaseOrderId, $quantityToReceive): void {
            $purchaseOrder = $this->database->table('purchase_orders', 'stockflow')
                ->where('id', '=', $purchaseOrderId)
                ->first();
            if ($purchaseOrder === null || in_array($purchaseOrder['status'], ['cancelled', 'received'], true)) {
                throw new InvalidArgumentException('This purchase order cannot receive stock.');
            }

            $items = $this->database->table('purchase_order_items', 'stockflow')
                ->where('purchase_order_id', '=', $purchaseOrderId)
                ->get();
            if (count($items) !== 1) {
                throw new RuntimeException('Stockflow receiving currently expects one line item per purchase order.');
            }
            $item = $items[0];
            $remaining = (int) $item['quantity_ordered'] - (int) $item['quantity_received'];
            if ($quantityToReceive > $remaining) {
                throw new InvalidArgumentException('Received quantity cannot exceed the remaining quantity on the order.');
            }

            $inventory = $this->database->table('inventory', 'stockflow')
                ->where('variant_id', '=', $item['variant_id'])
                ->first();
            if ($inventory === null) {
                throw new RuntimeException('Inventory record is missing for a purchase order variant.');
            }

            $received = (int) $item['quantity_received'] + $quantityToReceive;
            $updatedItem = $this->database->table('purchase_order_items', 'stockflow')
                ->where('id', '=', $item['id'])
                ->where('quantity_received', '=', (int) $item['quantity_received'])
                ->update(['quantity_received' => $received]);
            if ($updatedItem !== 1) {
                throw new RuntimeException('Purchase order changed concurrently; retry receiving.');
            }
            $updatedInventory = $this->database->table('inventory', 'stockflow')
                ->where('variant_id', '=', $item['variant_id'])
                ->where('quantity_on_hand', '=', (int) $inventory['quantity_on_hand'])
                ->update([
                    'quantity_on_hand' => (int) $inventory['quantity_on_hand'] + $quantityToReceive,
                ]);
            if ($updatedInventory !== 1) {
                throw new RuntimeException('Inventory changed concurrently; retry receiving.');
            }
            $this->database->table('inventory_movements', 'stockflow')->insert([
                'variant_id' => $item['variant_id'],
                'quantity_delta' => $quantityToReceive,
                'movement_type' => 'receipt',
                'reason' => 'Received purchase order ' . $purchaseOrder['order_number'],
                'reference_type' => 'purchase_order',
                'reference_id' => $purchaseOrderId,
            ]);

            $this->database->table('purchase_orders', 'stockflow')
                ->where('id', '=', $purchaseOrderId)
                ->update([
                    'status' => $received >= (int) $item['quantity_ordered'] ? 'received' : 'partially_received',
                ]);
        });
    }

    public function getStoreSettings(): array
    {
        $saved = $this->settings->getSetting('stockflow.settings')?->getValue() ?? [];
        return [
            'store_name' => $saved['store_name'] ?? (getenv('APP_NAME') ?: 'Stockflow'),
            'currency' => $this->getCurrency(),
            'timezone' => $saved['timezone'] ?? (getenv('TIMEZONE') ?: 'UTC'),
            'low_stock_alerts' => (string) ($saved['low_stock_alerts'] ?? '1'),
            'purchase_order_updates' => (string) ($saved['purchase_order_updates'] ?? '1'),
            'daily_digest' => (string) ($saved['daily_digest'] ?? '0'),
        ];
    }

    public function saveStoreSettings(array $data): void
    {
        $storeName = $this->requiredString($data, 'store_name', 'Store name');
        $currency = $this->choice(
            strtoupper($this->inputString($data['currency'] ?? '', 'Currency')),
            ['USD', 'EUR', 'GBP', 'MWK', 'ZAR'],
            'currency'
        );
        new Currency($currency);
        $currentCurrency = $this->getCurrency();
        if (
            $currency !== $currentCurrency
            && $this->database->table('product_variants', 'stockflow')->exists()
        ) {
            throw new InvalidArgumentException(
                'Default currency cannot be changed after variants exist because the schema stores variant prices without a currency code.'
            );
        }
        $timezone = $this->inputString($data['timezone'] ?? '', 'Timezone');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Select a valid timezone.');
        }

        $this->settings->createSetting('stockflow.settings', [
            'store_name' => $storeName,
            'currency' => $currency,
            'timezone' => $timezone,
            'low_stock_alerts' => $this->booleanChoice($data['low_stock_alerts'] ?? '0'),
            'purchase_order_updates' => $this->booleanChoice($data['purchase_order_updates'] ?? '0'),
            'daily_digest' => $this->booleanChoice($data['daily_digest'] ?? '0'),
        ]);
    }

    private function formatOrderTotal(array $order): string
    {
        return $this->formatMoney($order['grand_total'], (string) $order['currency_code']);
    }

    private function formatMoney(mixed $amount, ?string $currency = null): string
    {
        return new Price((float) $amount, $currency ?? $this->getCurrency())->format();
    }

    private function money(mixed $value, string $label): Price
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            throw new InvalidArgumentException($label . ' must be a valid amount.');
        }
        $amount = (float) $value;
        if (!is_finite($amount)) {
            throw new InvalidArgumentException($label . ' must be finite.');
        }
        return new Price($amount, $this->getCurrency());
    }

    private function getCurrency(): string
    {
        $currency = strtoupper((string) (
            $this->settings->getSetting('stockflow.settings')?->get('currency')
            ?? getenv('STOCKFLOW_CURRENCY')
            ?: 'USD'
        ));
        new Currency($currency);
        return $currency;
    }

    private function normalizeSlug(string $value, string $label): string
    {
        $transliterated = function_exists('iconv')
            ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value)
            : false;
        $source = $transliterated === false ? $value : $transliterated;
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $source), '-'));
        if ($slug === '') {
            throw new InvalidArgumentException($label . ' must contain at least one letter or number to generate a URL slug.');
        }
        return $slug;
    }

    private function validateProductImage(UploadedFile $image): void
    {
        if (!$image->isValid()) {
            throw new InvalidArgumentException('Product image upload failed.');
        }
        if ($image->getSize() > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Product image must be no larger than 5 MB.');
        }

        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $mimeType = $image->getMimeType();
        if (!isset($allowedMimeTypes[$mimeType])) {
            throw new InvalidArgumentException('Product image must be a JPEG, PNG, WebP, or GIF image.');
        }
    }

    private function uploadProductImage(UploadedFile $image, ?string &$uploadedUri): string
    {
        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $extension = $allowedMimeTypes[$image->getMimeType()];
        $uploadedUri = 'public://uploads/stockflow/products/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $result = $this->fileSystem->uploadFile([
            'name' => 'product-image.' . $extension,
            'tmp_name' => $image->getPathname(),
            'size' => $image->getSize(),
            'error' => $image->getError(),
        ], $uploadedUri, [
            'allowed_types' => array_values($allowedMimeTypes),
            'max_size' => 5 * 1024 * 1024,
        ]);
        if (!($result['success'] ?? false)) {
            $uploadedUri = null;
            throw new RuntimeException($result['message'] ?? 'Product image could not be saved.');
        }
        return $this->fileSystem->getPublicUrl($uploadedUri);
    }

    private function requiredString(array $data, string $key, string $label): string
    {
        $value = $this->inputString($data[$key] ?? '', $label);
        if ($value === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return $value;
    }

    private function inputString(mixed $value, string $label): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException($label . ' must be a single value.');
        }
        return trim((string) ($value ?? ''));
    }

    private function integer(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false) {
            throw new InvalidArgumentException($label . ' must be a whole number.');
        }
        return $validated;
    }

    private function choice(mixed $value, array $choices, string $label): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new InvalidArgumentException('Select a valid ' . strtolower($label) . '.');
        }
        return $value;
    }

    private function booleanChoice(mixed $value): string
    {
        return $this->choice($value, ['0', '1'], 'preference');
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
