<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Controller;

use DateTime;
use Exception;
use Mpdf\MpdfException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Form\FormBuilder;
use Simp\Pindrop\Form\FormState;
use Simp\Pindrop\Mail\MailManager;
use Simp\Pindrop\Modules\commerce_store\src\Checkout\CheckoutManager;
use Simp\Pindrop\Modules\commerce_store\src\Checkout\Workflow\CheckoutWorkflowInterface;
use Simp\Pindrop\Modules\commerce_store\src\Order\Calculator;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderDraft;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderManager;
use Simp\Pindrop\Modules\commerce_store\src\Payments\Payment;
use Simp\Pindrop\Modules\commerce_store\src\Payments\PaymentGatewayInterface;
use Simp\Pindrop\Modules\commerce_store\src\Payments\PaymentManager;
use Simp\Pindrop\Modules\commerce_store\src\Plugin\Adjustment;
use Simp\Pindrop\Modules\commerce_store\src\Services\Currency;
use Simp\Pindrop\Modules\commerce_store\src\Services\Product;
use Simp\Pindrop\Modules\commerce_store\src\Services\ProductVariation;
use Simp\Pindrop\Modules\commerce_store\src\Services\ProductVariationAttributes;
use Simp\Pindrop\Modules\commerce_store\src\Services\Store;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CommerceStoreController extends ControllerBase
{
    public function __construct(
        protected Currency $currency,
        protected Product $product,
        protected ProductVariation $variation,
        protected Store $store,
        protected ProductVariationAttributes $variationAttributes,
        protected OrderManager $orderManager,
        protected ContainerInterface $container
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new self(
            $container->get('commerce_store.currencies'),
            $container->get('commerce_store.products'),
            $container->get('commerce_store.product_variations'),
            $container->get('commerce_store.store'),
            $container->get('commerce_store.product_variation_attributes'),
            $container->get('commerce_store.order_manager'),
            $container
        );
    }

    public function storeDashboard(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig("@commerce_store/main/dashboard.twig", []);
    }

    public function storeSettings(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $storeData = null;

        try {

            // Get existing store for current user
            $store = $this->store->getStoreByUserId($userId);

            if ($request->isMethod('POST')) {
                $data = $this->processStoreSettingsForm($request);
                
                if ($store) {
                    // Update existing store
                    $success = $this->store->updateStore($store['id'], $data);
                    if ($success) {
                        $success = "Store settings updated successfully!";
                        $store = $this->store->getStore($store['id']); // Refresh data
                    }
                } else {
                    // Create new store
                    $data['user_id'] = $userId;

                    $storeId = $this->store->createStore($data);
                    if ($storeId) {
                        $success = "Store created successfully!";
                        $store = $this->store->getStore($storeId);
                    }
                }

                // Handle AJAX requests
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse([
                        'success' => $success,
                        'error' => $error,
                        'store' => $store
                    ]);
                }
            }

            // Prepare store data for form
            if ($store) {
                $storeData = $this->store->getStoreSettings($store['id']);
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
            
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $error
                ], 400);
            }
        }

        return $this->renderTwig("@commerce_store/store/store_settings.twig", [
            'store' => $storeData,
            'currencies' => $this->currency->getSimplifiedCurrencies(),
            'success' => $success,
            'error' => $error
        ]);
    }

    /**
     * Process store settings form data
     */
    protected function processStoreSettingsForm(Request $request): array
    {
        $data = [];
        
        // Basic Information
        $data['store_name'] = $request->request->get('store_name');
        $data['store_slug'] = $request->request->get('store_slug');
        $data['store_description'] = $request->request->get('store_description');

        // Contact Information
        $data['store_email'] = $request->request->get('store_email');
        $data['store_phone'] = $request->request->get('store_phone');
        $data['store_website'] = $request->request->get('store_website');

        // Address Information
        $data['store_address'] = $request->request->get('store_address');
        $data['store_city'] = $request->request->get('store_city');
        $data['store_state'] = $request->request->get('store_state');
        $data['store_country'] = $request->request->get('store_country');
        $data['store_postal_code'] = $request->request->get('store_postal_code');

        // Business Information
        $data['business_type'] = $request->request->get('business_type');
        $data['business_registration_number'] = $request->request->get('business_registration_number');
        $data['tax_id'] = $request->request->get('tax_id');

        // Store Configuration
        $data['currency'] = $request->request->get('currency');
        $data['timezone'] = $request->request->get('timezone');
        $data['language'] = $request->request->get('language');
        $data['commission_rate'] = $request->request->get('commission_rate');

        // Store Status
        $data['store_status'] = $request->request->get('store_status');
        $data['is_featured'] = $request->request->get('is_featured') ? 1 : 0;

        // Handle file uploads
        $logoFile = $request->files->get('store_logo');
        if ($logoFile && $logoFile->isValid()) {
            $data['store_logo'] = $logoFile;
        }

        $bannerFile = $request->files->get('store_banner');
        if ($bannerFile && $bannerFile->isValid()) {
            $data['store_banner'] = $bannerFile;
        }

        // Handle social links as JSON
        $socialLinks = [];
        $socialFields = ['social_facebook', 'social_twitter', 'social_instagram', 'social_linkedin'];
        foreach ($socialFields as $field) {
            $value = $request->request->get($field);
            if (!empty($value)) {
                $platform = str_replace('social_', '', $field);
                $socialLinks[$platform] = $value;
            }
        }
        if (!empty($socialLinks)) {
            $data['social_links'] = $socialLinks;
        }

        // Filter out empty values
        return array_filter($data, function($value) {
            if (is_string($value)) {
                return trim($value) !== '';
            }
            return $value !== null;
        });
    }

    /**
     * Process product form data
     */
    protected function processProductForm(Request $request): array
    {
        $data = [];

        // Basic Information
        $data['sku'] = $request->request->get('sku');
        $data['name'] = $request->request->get('name');
        $data['slug'] = $request->request->get('slug');
        $data['description'] = $request->request->get('description');
        $data['short_description'] = $request->request->get('short_description');
        
        // Product Type & Status
        $data['type'] = $request->request->get('type', 'simple');
        $data['status'] = $request->request->get('status', 'draft');
        $data['featured'] = $request->request->get('featured') ? 1 : 0;
        $data['catalog_visibility'] = $request->request->get('catalog_visibility', 'visible');
        
        // Pricing
        $data['regular_price'] = $request->request->get('regular_price');
        $data['sale_price'] = $request->request->get('sale_price');
        $data['sale_price_start_date'] = $request->request->get('sale_price_start_date');
        $data['sale_price_end_date'] = $request->request->get('sale_price_end_date');
        
        // Tax
        $data['tax_status'] = $request->request->get('tax_status', 'taxable');
        $data['tax_class'] = $request->request->get('tax_class');
        
        // Inventory
        $data['manage_stock'] = $request->request->get('manage_stock') ? 1 : 0;
        $data['stock_quantity'] = $request->request->get('stock_quantity', 0);
        $data['stock_status'] = $request->request->get('stock_status', 'instock');
        $data['backorders_allowed'] = $request->request->get('backorders_allowed') ? 1 : 0;
        $data['sold_individually'] = $request->request->get('sold_individually') ? 1 : 0;
        
        // Shipping
        $data['weight'] = $request->request->get('weight');
        $data['dimensions_length'] = $request->request->get('dimensions_length');
        $data['dimensions_width'] = $request->request->get('dimensions_width');
        $data['dimensions_height'] = $request->request->get('dimensions_height');
        $data['shipping_class'] = $request->request->get('shipping_class');
        $data['shipping_required'] = $request->request->get('shipping_required') ? 1 : 0;
        $data['purchase_note'] = $request->request->get('purchase_note');
        
        // Digital Products
        $data['virtual'] = $request->request->get('virtual') ? 1 : 0;
        $data['downloadable'] = $request->request->get('downloadable') ? 1 : 0;
        $data['download_limit'] = $request->request->get('download_limit');
        $data['download_expiry'] = $request->request->get('download_expiry');
        $data['external_url'] = $request->request->get('external_url');
        $data['button_text'] = $request->request->get('button_text');
        
        // Linked Products
        $data['parent_id'] = $request->request->get('parent_id');
        $data['menu_order'] = $request->request->get('menu_order', 0);
        
        // Handle arrays as JSON
        $arrayFields = ['categories', 'tags', 'attributes', 'default_attributes', 'variations', 'meta_data', 'grouped_products', 'upsell_products', 'cross_sell_products'];
        foreach ($arrayFields as $field) {
            $value = $request->request->get($field);
            if (!empty($value)) {
                if (is_array($value)) {
                    $data[$field] = $value;
                } else {
                    $data[$field] = json_decode($value, true) ?: [];
                }
            }
        }
        
        // Reviews
        $data['reviews_allowed'] = $request->request->get('reviews_allowed') ? 1 : 0;
        
        // Filter out empty values
        return array_filter($data, function($value) {
            if (is_string($value)) {
                return trim($value) !== '';
            }
            return $value !== null;
        });
    }

    /**
     * Get current user ID (placeholder - implement based on your auth system)
     */
    protected function getCurrentUserId(): int
    {
        return $this->container->get('current_user')->id();
    }

    public function createProduct(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $productData = null;
        $submittedData = [];

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to create products');
            }

            if ($request->isMethod('POST')) {
                $submittedData = $this->processProductForm($request);
                $submittedData['store_id'] = $store['id'];
                $submittedData['created_by'] = $userId;

                $productId = $this->product->createProduct($submittedData);
                if ($productId) {
                    $success = "Product created successfully!";
                    $productData = $this->product->getProduct($productId);
                    
                    // Redirect to product list or edit page after successful creation
                    return $this->redirect(Url::routeByName('commerce_store.products'));
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig("@commerce_store/store/create_product.twig", [
            'store' => $store,
            'product' => $productData,
            'submitted_data' => $submittedData,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function listProducts(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $products = [];
        $store = null;

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to view products');
            }

            // Get pagination parameters
            $page = max(1, intval($request->query->get('page', 1)));
            $perPage = in_array($request->query->get('per_page'), [10, 25, 50, 100]) 
                ? intval($request->query->get('per_page')) 
                : 10;
            $offset = ($page - 1) * $perPage;

            // Get filters from request
            $filters = [
                'status' => $request->query->get('status'),
                'type' => $request->query->get('type'),
                'featured' => $request->query->get('featured'),
                'catalog_visibility' => $request->query->get('catalog_visibility'),
                'search' => $request->query->get('search'),
                'limit' => $perPage,
                'offset' => $offset
            ];

            // Remove empty filters except limit and offset
            $filters = array_filter($filters, function($value, $key) {
                return ($key === 'limit' || $key === 'offset') || 
                       ($value !== null && $value !== '');
            }, ARRAY_FILTER_USE_BOTH);

            // Get products for the store
            $products = $this->product->getProductsByStore($store['id'], $filters);
            
            // Get total count for pagination
            $countFilters = $filters;
            unset($countFilters['limit'], $countFilters['offset']);
            $totalItems = $this->product->getProductsCountByStore($store['id'], $countFilters);
            $totalPages = (int) ceil($totalItems / $perPage);

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig("@commerce_store/store/list_products.twig", [
            'store' => $store,
            'products' => $products,
            'filters' => $filters ?? [],
            'current_page' => $page ?? 1,
            'per_page' => $perPage ?? 10,
            'total_items' => $totalItems ?? 0,
            'total_pages' => $totalPages ?? 1,
            'status' => $request->query->get('status'),
            'search' => $request->query->get('search'),
            'error' => $error
        ]);
    }

    public function editProduct(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $product = null;
        $submittedData = [];

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to edit products');
            }

            // Get product ID from options or request
            $productId = $request->query->get('id');
            if (!$productId) {
                throw new Exception('Product ID is required');
            }

            // Get existing product
            $product = $this->product->getProduct($productId);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Check if user owns this product
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to edit this product');
            }

            if ($request->isMethod('POST')) {
                $submittedData = $this->processProductForm($request);
                $submittedData['store_id'] = $store['id'];
                $submittedData['updated_by'] = $userId;
                $submittedData['op'] = 'update';

                $updated = $this->product->updateProduct($productId, $submittedData);
                if ($updated) {
                    $success = "Product updated successfully!";
                    $product = $this->product->getProduct($productId); // Refresh data
                    
                    // Redirect to product list after successful update
                    return $this->redirect(Url::routeByName('commerce_store.products'));
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig("@commerce_store/store/edit_product.twig", [
            'store' => $store,
            'product' => $product,
            'submitted_data' => $submittedData ?: $product,
            'success' => $success,
            'error' => $error,
            'is_edit' => true
        ]);
    }

    public function deleteProduct(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to delete products');
            }

            // Get product ID from POST data
            $productId = $request->request->get('product_id');
            if (!$productId) {
                throw new Exception('Product ID is required');
            }

            // Get existing product
            $product = $this->product->getProduct($productId);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Check if user owns this product
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to delete this product');
            }

            // Soft delete the product (set deleted_at timestamp)
            $deleted = $this->product->deleteProduct($productId);
            if ($deleted) {
                $success = "Product deleted successfully!";
            } else {
                throw new Exception('Failed to delete product');
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        // Handle AJAX requests
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => $success,
                'error' => $error
            ], $error ? 400 : 200);
        }

        // Redirect back to a product list with a success/error message
        if ($success) {
            return $this->redirect(Url::routeByName('commerce_store.products'));
        }

        // If error, redirect back with an error message
        return $this->redirect(Url::routeByName('commerce_store.products.view', ['id' => $productId]));
    }

    public function viewProduct(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $product = null;

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to view products');
            }

            // Get product ID from query parameters
            $productId = $request->query->get('id');
            if (!$productId) {
                throw new Exception('Product ID is required');
            }

            // Get existing product
            $product = $this->product->getProduct($productId);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Check if user owns this product
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to view this product');
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig("@commerce_store/store/view_product.twig", [
            'store' => $store,
            'product' => $product,
            'error' => $error
        ]);
    }

    public function importProducts(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $importStats = [];

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to import products');
            }

            if ($request->isMethod('POST')) {
                $file = $request->files->get('import_file');
                if (!$file) {
                    throw new Exception('No file uploaded');
                }

                // Validate file
                if ($file->getClientOriginalExtension() !== 'csv') {
                    throw new Exception('Only CSV files are allowed');
                }

                if ($file->getSize() > 10 * 1024 * 1024) { // 10MB
                    throw new Exception('File size must be less than 10MB');
                }

                // Get import options
                $updateExisting = $request->request->get('update_existing') ? true : false;
                $skipFirstRow = $request->request->get('skip_first_row') ? true : false;

                // Read CSV file
                $csvPath = $file->getRealPath();
                $handle = fopen($csvPath, 'r');
                if (!$handle) {
                    throw new Exception('Failed to read uploaded file');
                }

                $imported = 0;
                $updated = 0;
                $errors = [];
                $rowNumber = 0;

                while (($data = fgetcsv($handle, 1000, ',', '"', '\\')) !== false) {
                    $rowNumber++;
                    
                    // Skip header row if requested
                    if ($skipFirstRow && $rowNumber === 1) {
                        continue;
                    }

                    try {
                        // Map CSV columns to product fields (32 columns total)
                        $productData = [
                            'store_id' => $store['id'],
                            'created_by' => $userId,
                            'sku' => trim($data[0] ?? ''),
                            'name' => trim($data[1] ?? ''),
                            'slug' => trim($data[2] ?? ''),
                            'short_description' => trim($data[3] ?? ''),
                            'description' => trim($data[4] ?? ''),
                            'type' => !empty(trim($data[5] ?? '')) ? trim($data[5]) : 'simple',
                            'regular_price' => is_numeric($data[6]) ? floatval($data[6]) : 0,
                            'sale_price' => !empty(trim($data[7] ?? '')) ? floatval($data[7]) : null,
                            'sale_price_start_date' => !empty(trim($data[8] ?? '')) ? trim($data[8]) : null,
                            'sale_price_end_date' => !empty(trim($data[9] ?? '')) ? trim($data[9]) : null,
                            'status' => !empty(trim($data[10] ?? '')) ? strtolower(trim($data[10])) : 'draft',
                            'featured' => !empty(trim($data[11] ?? '')) ? intval($data[11]) : 0,
                            'catalog_visibility' => !empty(trim($data[12] ?? '')) ? trim($data[12]) : 'visible',
                            'stock_quantity' => is_numeric($data[13]) ? intval($data[13]) : 0,
                            'stock_status' => !empty(trim($data[14] ?? '')) ? trim($data[14]) : 'instock',
                            'manage_stock' => !empty(trim($data[15] ?? '')) ? intval($data[15]) : 1,
                            'backorders_allowed' => !empty(trim($data[16] ?? '')) ? intval($data[16]) : 0,
                            'sold_individually' => !empty(trim($data[17] ?? '')) ? intval($data[17]) : 0,
                            'weight' => is_numeric($data[18]) ? floatval($data[18]) : null,
                            'dimensions_length' => is_numeric($data[19]) ? floatval($data[19]) : null,
                            'dimensions_width' => is_numeric($data[20]) ? floatval($data[20]) : null,
                            'dimensions_height' => is_numeric($data[21]) ? floatval($data[21]) : null,
                            'shipping_class' => trim($data[22] ?? ''),
                            'shipping_required' => !empty(trim($data[23] ?? '')) ? intval($data[23]) : 1,
                            'purchase_note' => trim($data[24] ?? ''),
                            'virtual' => !empty(trim($data[25] ?? '')) ? intval($data[25]) : 0,
                            'downloadable' => !empty(trim($data[26] ?? '')) ? intval($data[26]) : 0,
                            'download_limit' => is_numeric($data[27]) ? intval($data[27]) : null,
                            'download_expiry' => is_numeric($data[28]) ? intval($data[28]) : null,
                            'external_url' => trim($data[29] ?? ''),
                            'button_text' => trim($data[30] ?? ''),
                            'tax_status' => !empty(trim($data[31] ?? '')) ? trim($data[31]) : 'taxable',
                            'tax_class' => trim($data[32] ?? ''),
                            'reviews_allowed' => !empty(trim($data[33] ?? '')) ? intval($data[33]) : 1,
                            'menu_order' => is_numeric($data[34]) ? intval($data[34]) : 0
                        ];

                        // Validate required fields
                        if (empty($productData['sku']) || empty($productData['name'])) {
                            $errors[] = "Row $rowNumber: SKU and Name are required";
                            continue;
                        }

                        if (!is_numeric($productData['regular_price']) || $productData['regular_price'] <= 0) {
                            $errors[] = "Row $rowNumber: Regular price must be greater than 0";
                            continue;
                        }

                        // Validate optional fields
                        if ($productData['sale_price'] !== null && $productData['sale_price'] <= 0) {
                            $errors[] = "Row $rowNumber: Sale price must be greater than 0";
                            continue;
                        }

                        if ($productData['stock_quantity'] < 0) {
                            $errors[] = "Row $rowNumber: Stock quantity cannot be negative";
                            continue;
                        }

                        // Check if product exists (for update)
                        $existingProduct = $this->product->getProductBySku($productData['sku'], $store['id']);

                        if ($existingProduct && $updateExisting) {
                            // Update existing product
                            $productData['updated_by'] = $userId;
                            $updatedProduct = $this->product->updateProduct($existingProduct['id'], $productData);
                            if ($updatedProduct) {
                                $updated++;
                            }
                        } elseif (!$existingProduct) {
                            // Create new product
                            $newProductId = $this->product->createProduct($productData);
                            if ($newProductId) {
                                $imported++;
                            }
                        } else {
                            $errors[] = "Row $rowNumber: Product with SKU '{$productData['sku']}' already exists. Use 'Update existing' option to overwrite.";
                        }

                    } catch (Exception $e) {
                        $errors[] = "Row $rowNumber: " . $e->getMessage();
                    }
                }

                fclose($handle);

                // Prepare import statistics
                $importStats = [
                    'imported' => $imported,
                    'updated' => $updated,
                    'errors' => count($errors),
                    'error_details' => $errors
                ];

                if ($imported > 0 || $updated > 0) {
                    $success = "Import completed! {$imported} products imported, {$updated} products updated.";
                    if (count($errors) > 0) {
                        $success .= " " . count($errors) . " errors encountered.";
                    }
                } else {
                    throw new Exception('No products were imported. Please check your file format and try again.');
                }
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        // Handle AJAX requests
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => $success,
                'error' => $error,
                'stats' => $importStats
            ], $error ? 400 : 200);
        }

        // For non-AJAX requests, redirect back with message
        return $this->redirect(Url::routeByName('commerce_store.products'));
    }

    public function importVariations(Request $request, string $route_name, array $options): Response|RedirectResponse
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $importStats = [];

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to import variations');
            }

            if ($request->isMethod('POST')) {
                $file = $request->files->get('import_file');

                if (!$file) {
                    throw new Exception('No file uploaded');
                }

                // Validate file type
                $allowedTypes = ['text/csv', 'text/plain', 'application/csv'];
                if (!in_array($file->getMimeType(), $allowedTypes)) {
                    throw new Exception('Only CSV files are allowed');
                }

                // Validate file size (10MB max)
                if ($file->getSize() > 10 * 1024 * 1024) {
                    throw new Exception('File size must be less than 10MB');
                }

                // Get import options
                $updateExisting = $request->request->get('update_existing') ? true : false;
                $skipFirstRow = $request->request->get('skip_first_row') ? true : false;

                // Read CSV file
                $filePath = $file->getRealPath();
                if (($handle = fopen($filePath, 'r')) === false) {
                    throw new Exception('Failed to read uploaded file');
                }

                $imported = 0;
                $updated = 0;
                $errors = [];
                $rowNumber = 0;

                // Skip header row if requested
                if ($skipFirstRow) {
                    fgetcsv($handle, 1000, ',', '"', '\\');
                    $rowNumber = 1;
                }


                // Process each row
                while (($row = fgetcsv($handle, 1000, ',', '"', '\\')) !== false) {
                    $rowNumber++;
                    
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        continue;
                    }

                    // Map CSV columns to variation data (matching template header)
                    $productSku = $row[1] ?? ''; // product_sku column

                    // Get product by SKU
                    $product = $this->product->getProductBySku($productSku);
                    if (!$product) {
                        $errors[] = "Row $rowNumber: Product with SKU '$productSku' not found";
                        continue;
                    }
                    
                    // Check if product is variable type
                    if ($product['type'] !== 'variable') {
                        $errors[] = "Row $rowNumber: Product with SKU '$productSku' is not a variable product";
                        continue;
                    }
                    
                    // Check if user owns this product
                    if ($product['store_id'] != $store['id']) {
                        $errors[] = "Row $rowNumber: You do not have permission to manage product with SKU '$productSku'";
                        continue;
                    }
                    
                    $variationData = [
                        'uuid' => $row[0] ?? null,
                        'product_id' => $product['id'],
                        'sku' => $row[2] ?? '',
                        'name' => $row[3] ?? '',
                        'slug' => $row[4] ?? '',
                        'description' => $row[5] ?? '',
                        'status' => $row[6] ?? 'draft',
                        'featured' => intval($row[7] ?? 0),
                        'catalog_visibility' => trim($row[8] ?? 'visible'),
                        'regular_price' => floatval($row[9] ?? 0),
                        'sale_price' => !empty($row[10] ?? '') ? floatval($row[10]) : null,
                        'sale_price_start_date' => $row[11] ?? null,
                        'sale_price_end_date' => $row[12] ?? null,
                        'tax_status' => $row[13] ?? 'taxable',
                        'tax_class' => $row[14] ?? null,
                        'manage_stock' => intval($row[15] ?? 1),
                        'stock_quantity' => intval($row[16] ?? 0),
                        'stock_status' => $row[17] ?? 'instock',
                        'backorders_allowed' => intval($row[18] ?? 0),
                        'sold_individually' => intval($row[19] ?? 0),
                        'weight' => !empty($row[20] ?? '') ? floatval($row[20]) : null,
                        'dimensions_length' => !empty($row[21] ?? '') ? floatval($row[21]) : null,
                        'dimensions_width' => !empty($row[22] ?? '') ? floatval($row[22]) : null,
                        'dimensions_height' => !empty($row[23] ?? '') ? floatval($row[23]) : null,
                        'shipping_class' => $row[24] ?? null,
                        'shipping_required' => intval($row[25] ?? 1),
                        'purchase_note' => $row[26] ?? null,
                        'menu_order' => intval($row[27] ?? 0),
                        'virtual' => intval($row[28] ?? 0),
                        'downloadable' => intval($row[29] ?? 0),
                        'download_limit' => !empty($row[30] ?? '') ? intval($row[30]) : null,
                        'download_expiry' => !empty($row[31] ?? '') ? intval($row[31]) : null,
                        'image_id' => !empty($row[32] ?? '') ? intval($row[32]) : null,
                        'attributes' => $row[33] ?? null,
                        'meta_data' => $row[34] ?? null,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                        'published_at' => $row[37] ?? null
                    ];

                   // dd($variationData, $product);
                    // Validate required fields
                    if (empty($variationData['sku']) || empty($variationData['name'])) {
                        $errors[] = "Row $rowNumber: SKU and Name are required";
                        continue;
                    }

                    // Validate ENUM fields
                    $validCatalogVisibility = ['visible', 'catalog', 'search', 'hidden'];
                    if (!in_array($variationData['catalog_visibility'], $validCatalogVisibility)) {
                        $errors[] = "Row $rowNumber: Invalid catalog_visibility '{$variationData['catalog_visibility']}'. Must be one of: " . implode(', ', $validCatalogVisibility);
                        continue;
                    }

                    $validStatus = ['draft', 'pending', 'private', 'publish', 'trash'];
                    if (!in_array($variationData['status'], $validStatus)) {
                        $errors[] = "Row $rowNumber: Invalid status '{$variationData['status']}'. Must be one of: " . implode(', ', $validStatus);
                        continue;
                    }

                    if (!is_numeric($variationData['regular_price']) || $variationData['regular_price'] <= 0) {
                        $errors[] = "Row $rowNumber: Regular price must be greater than 0";
                        continue;
                    }

                    // Check if variation already exists
                    $existingVariation = $this->variation->getVariationBySku( $variationData['sku'], $product['id']);
                    if ($existingVariation && !$updateExisting) {
                        $errors[] = "Row $rowNumber: Variation with SKU '{$variationData['sku']}' already exists. Use 'Update existing' option to overwrite.";
                        continue;
                    }

                    // Create or update variation
                    if ($existingVariation && $updateExisting) {
                        $this->variation->updateVariation($existingVariation['id'], $variationData);
                        $updated++;
                    } else {
                        $this->variation->createVariation($variationData);
                        $imported++;
                    }
                }

                fclose($handle);

                // Prepare import statistics
                $importStats = [
                    'imported' => $imported,
                    'updated' => $updated,
                    'errors' => count($errors),
                    'error_details' => $errors
                ];

                if ($imported > 0 || $updated > 0) {
                    $success = "Import completed! {$imported} variations imported, {$updated} variations updated.";
                    if (count($errors) > 0) {
                        $success .= " " . count($errors) . " errors encountered.";
                    }
                } else {
                    throw new Exception('No variations were imported. Please check your file format and try again.');
                }
            }

            // For AJAX requests, return JSON response
            return new RedirectResponse(Url::routeByName('commerce_store.products'));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        // For non-AJAX requests, redirect back to the products list
        return $this->redirect(Url::routeByName('commerce_store.products'));
    }

    public function productVariations(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $product = null;
        $variations = [];

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to manage product variations');
            }

            // Get product ID from URL options
            $productId = $request->query->get('id');
            if (!$productId) {
                throw new Exception('Product ID is required');
            }

            // Get product
            $product = $this->product->getProduct($productId);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Check if user owns this product
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to manage this product');
            }

            // Check if product is variable type
            if ($product['type'] !== 'variable') {
                throw new Exception('Only variable products can have variations');
            }

            // Handle form submission for adding/editing variations
            if ($request->isMethod('POST')) {
                $variationData = [
                    'product_id' => $productId,
                    'sku' => $request->request->get('sku'),
                    'name' => $request->request->get('name'),
                    'regular_price' => $request->request->get('regular_price'),
                    'sale_price' => $request->request->get('sale_price'),
                    'stock_quantity' => $request->request->get('stock_quantity'),
                    'weight' => $request->request->get('weight'),
                    'attributes' => $request->request->get('attributes', []),
                    'status' => $request->request->get('status', 'publish')
                ];

                // Validate variation data
                if (empty($variationData['sku']) || empty($variationData['name'])) {
                    throw new Exception('SKU and Name are required for variations');
                }

                if (!is_numeric($variationData['regular_price']) || $variationData['regular_price'] <= 0) {
                    throw new Exception('Regular price must be greater than 0');
                }

                $variationId = $request->request->get('variation_id');
                
                if ($variationId) {
                    // Update existing variation
                    $this->product->updateVariation($variationId, $variationData);
                    $success = "Product variation updated successfully!";
                } else {
                    // Create new variation
                    $this->product->createVariation($variationData);
                    $success = "Product variation created successfully!";
                }

                // Refresh variations list
                $variations = $this->product->getProductVariations($productId, [
                    'limit' => 10,
                    'offset' => 0
                ]);
                $totalItems = $this->product->getProductVariationsCount($productId);
                $totalPages = (int) ceil($totalItems / 10);
                $filters = [];
            } else {
                // Get pagination parameters
                $page = max(1, intval($request->query->get('page', 1)));
                $perPage = in_array($request->query->get('per_page'), [10, 25, 50, 100]) 
                    ? intval($request->query->get('per_page')) 
                    : 10;
                $offset = ($page - 1) * $perPage;

                // Get filters from request
                $filters = [
                    'status' => $request->query->get('status'),
                    'stock_status' => $request->query->get('stock_status'),
                    'featured' => $request->query->get('featured'),
                    'catalog_visibility' => $request->query->get('catalog_visibility'),
                    'manage_stock' => $request->query->get('manage_stock'),
                    'search' => $request->query->get('search'),
                    'limit' => $perPage,
                    'offset' => $offset
                ];

                // Remove empty filters except limit and offset
                $filters = array_filter($filters, function($value, $key) {
                    return ($key === 'limit' || $key === 'offset') || 
                           ($value !== null && $value !== '');
                }, ARRAY_FILTER_USE_BOTH);

                // Get existing variations with pagination and filters
                $variations = $this->product->getProductVariations($productId, $filters);
                
                // Get total count for pagination
                $countFilters = $filters;
                unset($countFilters['limit'], $countFilters['offset']);
                $totalItems = $this->product->getProductVariationsCount($productId, $countFilters);
                $totalPages = (int) ceil($totalItems / $perPage);
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig("@commerce_store/store/product_variations.twig", [
            'store' => $store,
            'product' => $product,
            'variations' => $variations,
            'error' => $error,
            'success' => $success,
            'filters' => $filters ?? [],
            'current_page' => $page ?? 1,
            'per_page' => $perPage ?? 10,
            'total_items' => $totalItems ?? 0,
            'total_pages' => $totalPages ?? 1
        ]);
    }

    public function createProductVariation(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $product = null;
        $formData = []; // To store submitted form data

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to create product variations');
            }

            // Get product ID from URL options
            $productId = $request->query->get('id') ?? null;
            if (!$productId) {
                throw new Exception('Product ID is required');
            }

            // Get product
            $product = $this->product->getProduct($productId);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Check if user owns this product
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to manage this product');
            }

            // Check if product is variable type
            if ($product['type'] !== 'variable') {
                throw new Exception('Only variable products can have variations');
            }

            // Handle form submission
            if ($request->isMethod('POST')) {
                // Capture all submitted data for form repopulation
                $formData = $request->request->all();
                
                $variationData = [
                    'product_id' => $productId,
                    'sku' => $request->request->get('sku'),
                    'name' => $request->request->get('name'),
                    'slug' => $request->request->get('slug'),
                    'description' => $request->request->get('description'),
                    'status' => $request->request->get('status', 'draft'),
                    'featured' => $request->request->get('featured', 0),
                    'catalog_visibility' => $request->request->get('catalog_visibility', 'visible'),
                    'regular_price' => $request->request->get('regular_price'),
                    'sale_price' => $request->request->get('sale_price'),
                    'sale_price_start_date' => $request->request->get('sale_price_start_date'),
                    'sale_price_end_date' => $request->request->get('sale_price_end_date'),
                    'tax_status' => $request->request->get('tax_status', 'taxable'),
                    'tax_class' => $request->request->get('tax_class'),
                    'manage_stock' => $request->request->get('manage_stock', 1),
                    'stock_quantity' => $request->request->get('stock_quantity', 0),
                    'stock_status' => $request->request->get('stock_status', 'instock'),
                    'backorders_allowed' => $request->request->get('backorders_allowed', 0),
                    'sold_individually' => $request->request->get('sold_individually', 0),
                    'weight' => $request->request->get('weight'),
                    'dimensions_length' => $request->request->get('dimensions_length'),
                    'dimensions_width' => $request->request->get('dimensions_width'),
                    'dimensions_height' => $request->request->get('dimensions_height'),
                    'shipping_class' => $request->request->get('shipping_class'),
                    'shipping_required' => $request->request->get('shipping_required', 1),
                    'purchase_note' => $request->request->get('purchase_note'),
                    'menu_order' => $request->request->get('menu_order', 0),
                    'virtual' => $request->request->get('virtual', 0),
                    'downloadable' => $request->request->get('downloadable', 0),
                    'download_limit' => $request->request->get('download_limit'),
                    'download_expiry' => $request->request->get('download_expiry'),
                    'image_id' => $request->request->get('image_id'),
                    'created_by' => $userId,
                    'updated_by' => $userId
                ];

                // Validate variation data
                if (empty($variationData['sku']) || empty($variationData['name'])) {
                    throw new Exception('SKU and Name are required for variations');
                }

                if (!is_numeric($variationData['regular_price']) || $variationData['regular_price'] <= 0) {
                    throw new Exception('Regular price must be greater than 0');
                }

                // Create new variation using ProductVariation service
                $this->variation->createVariation($variationData);
                $success = "Product variation created successfully!";

                // Redirect back to variations list
                return $this->redirect(Url::routeByName('commerce_store.products.variations', ['id' => $productId]));
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig("@commerce_store/store/create_variation.twig", [
            'store' => $store,
            'product' => $product,
            'error' => $error,
            'success' => $success,
            'formData' => $formData ?? []
        ]);
    }

    public function editProductVariation(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $product = null;
        $variation = null;
        $formData = []; // To store submitted form data

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to edit product variations');
            }

            // Get product ID from URL options
            $productId = $request->query->get('id');
            $variationId = $request->query->get('variation_id');
            if (!$productId || !$variationId) {
                throw new Exception('Product ID and Variation ID are required');
            }

            // Get product
            $product = $this->product->getProduct($productId);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Get variation
            $variation = $this->variation->getVariation($variationId);
            if (!$variation) {
                throw new Exception('Variation not found');
            }

            // Check if user owns this product
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to manage this product');
            }

            // Handle form submission
            if ($request->isMethod('POST')) {
                // Capture all submitted data for form repopulation
                $formData = $request->request->all();
                
                $variationData = [
                    'sku' => $request->request->get('sku'),
                    'name' => $request->request->get('name'),
                    'slug' => $request->request->get('slug'),
                    'description' => $request->request->get('description'),
                    'status' => $request->request->get('status', 'draft'),
                    'featured' => $request->request->get('featured', 0),
                    'catalog_visibility' => $request->request->get('catalog_visibility', 'visible'),
                    'regular_price' => $request->request->get('regular_price'),
                    'sale_price' => $request->request->get('sale_price'),
                    'sale_price_start_date' => $request->request->get('sale_price_start_date'),
                    'sale_price_end_date' => $request->request->get('sale_price_end_date'),
                    'tax_status' => $request->request->get('tax_status', 'taxable'),
                    'tax_class' => $request->request->get('tax_class'),
                    'manage_stock' => $request->request->get('manage_stock', 1),
                    'stock_quantity' => $request->request->get('stock_quantity', 0),
                    'stock_status' => $request->request->get('stock_status', 'instock'),
                    'backorders_allowed' => $request->request->get('backorders_allowed', 0),
                    'sold_individually' => $request->request->get('sold_individually', 0),
                    'weight' => $request->request->get('weight'),
                    'dimensions_length' => $request->request->get('dimensions_length'),
                    'dimensions_width' => $request->request->get('dimensions_width'),
                    'dimensions_height' => $request->request->get('dimensions_height'),
                    'shipping_class' => $request->request->get('shipping_class'),
                    'shipping_required' => $request->request->get('shipping_required', 1),
                    'purchase_note' => $request->request->get('purchase_note'),
                    'menu_order' => $request->request->get('menu_order', 0),
                    'virtual' => $request->request->get('virtual', 0),
                    'downloadable' => $request->request->get('downloadable', 0),
                    'download_limit' => $request->request->get('download_limit'),
                    'download_expiry' => $request->request->get('download_expiry'),
                    'image_id' => $request->request->get('image_id'),
                    'updated_by' => $userId,
                    'product_id' => $productId,
                    'op' => 'update'
                ];

                // Validate variation data
                if (empty($variationData['sku']) || empty($variationData['name'])) {
                    throw new Exception('SKU and Name are required for variations');
                }

                if (!is_numeric($variationData['regular_price']) || $variationData['regular_price'] <= 0) {
                    throw new Exception('Regular price must be greater than 0');
                }

                // Update existing variation using ProductVariation service
                $this->variation->updateVariation($variationId, $variationData);
                $success = "Product variation updated successfully!";

                // Redirect back to variations list
                return $this->redirect(Url::routeByName('commerce_store.products.variations', ['id' => $productId]));
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig("@commerce_store/store/edit_variation.twig", [
            'store' => $store,
            'product' => $product,
            'variation' => $variation,
            'error' => $error,
            'success' => $success,
            'formData' => $formData ?? []
        ]);
    }

    public function deleteProductVariations(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $product = null;
        $variation = null;

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to delete product variations');
            }

            // Get product ID from URL options
            $variationId = $request->query->get('variation_id');
            if (!$variationId) {
                throw new Exception('Product ID and Variation ID are required');
            }

            $variation = $this->variation->getVariation($variationId);

            // Get product
            $product = $this->product->getProduct($variation['product_id']);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Get variation
            if (!$variation) {
                throw new Exception('Variation not found');
            }

            // Check if a user owns this product
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to delete this product variation');
            }

            // Delete variation (soft delete)
            $this->variation->deleteVariation($variationId);
            $success = "Product variation deleted successfully!";

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        
        // Redirect back to the variations list
        return $this->redirect(Url::routeByName('commerce_store.products.variations', ['id' => $product['id']]));
    }

    public function viewProductVariation(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $variation = null;
        $product = null;

        try {
            // Get variation ID from URL
            $variationId = $request->query->get('variation_id');
            if (!$variationId) {
                throw new Exception('Variation ID is required');
            }

            // Get variation
            $variation = $this->variation->getVariation($variationId);
            if (!$variation) {
                throw new Exception('Variation not found');
            }

            // Get product
            $product = $this->product->getProduct($variation['product_id']);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Get store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('Store not found');
            }

            // Check if user owns this product
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to view this variation');
            }

            // Get variation attributes if any
            $attributes = $this->variation->getVariationAttributes($variationId);

            // Get variation images if any
            $images = $this->variation->getVariationImages($variationId);

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig('@commerce_store/store/view_variation.twig', [
            'variation' => $variation,
            'product' => $product,
            'attributes' => $attributes ?? [],
            'images' => $images ?? [],
            'error' => $error
        ]);
    }

    public function viewVariationAttributes(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $variation = null;
        $product = null;
        $attributes = [];

        try {
            // Get variation ID from URL
            $variationId = $request->query->get('variation_id');
            if (!$variationId) {
                throw new Exception('Variation ID is required');
            }

            // Get variation
            $variation = $this->variation->getVariation($variationId);
            if (!$variation) {
                throw new Exception('Variation not found');
            }

            // Get product
            $product = $this->product->getProduct($variation['product_id']);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Get store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('Store not found');
            }

            // Check permissions
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to view this variation');
            }

            // Get variation attributes
            $attributes = $this->variation->getVariationAttributes($variationId);

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig('@commerce_store/store/view_variation_attributes.twig', [
            'variation' => $variation,
            'product' => $product,
            'attributes' => $attributes ?? [],
            'error' => $error
        ]);
    }

    public function createVariationAttribute(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;

        try {
            // Get variation ID from URL
            $variationId = $request->query->get('variation_id');
            if (!$variationId) {
                throw new Exception('Variation ID is required');
            }

            // Get variation
            $variation = $this->variation->getVariation($variationId);
            if (!$variation) {
                throw new Exception('Variation not found');
            }

            // Get product
            $product = $this->product->getProduct($variation['product_id']);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Get store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('Store not found');
            }

            // Check permissions
            if ($product['store_id'] != $store['id']) {
                throw new Exception('You do not have permission to modify this variation');
            }

            if ($request->isMethod('POST')) {
                // Get form data
                $attributeData = [
                    'variation_id' => $variationId,
                    'attribute_name' => $request->request->get('attribute_name'),
                    'attribute_value' => $request->request->get('attribute_value'),
                    'attribute_type' => $request->request->get('attribute_type'),
                    'attribute_order' => $request->request->get('attribute_order', 0),
                    'is_visible' => $request->request->get('is_visible') ? 1 : 0,
                    'is_variation' => $request->request->get('is_variation') ? 1 : 0
                ];

                // Handle boolean type
                if ($attributeData['attribute_type'] === 'boolean') {
                    $attributeData['attribute_value'] = $request->request->get('attribute_value') ? '1' : '0';
                }

                // Create attribute using ProductVariationAttributes service
                $attributeId = $this->variationAttributes->createAttribute($attributeData);

                if ($attributeId) {
                    $success = "Attribute created successfully!";
                } else {
                    throw new Exception('Failed to create attribute');
                }
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        // Redirect back to attributes page
        if ($success) {
            return $this->redirect(Url::routeByName('commerce_store.products.variations.attributes', [
                'id' => $product['id'],
                'variation_id' => $variationId
            ]) . '?success=' . urlencode($success));
        }

        // If error, show the attributes page with error
        return $this->viewVariationAttributes($request, $route_name, $options);
    }

    public function orders(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $orders = [];
        $stats = [];
        $recentActivities = [];

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to view orders');
            }

            // Get filters from request
            $page = max(1, (int) $request->query->get('page', 1));
            $perPage = 20;
            $offset = ($page - 1) * $perPage;

            $filters = [
                'status' => $request->query->get('status'),
                'payment_status' => $request->query->get('payment_status'),
                'order_number' => $request->query->get('search'),
                'store_id'   =>  $store['id'],
            ];

            $created_at = $request->query->get('date_from');
            $created_at2 = $request->query->get('date_to');
            $whereClauses = "";
            if (!empty($created_at) && !empty($created_at2)) {
                $whereClauses = "created_at BETWEEN '$created_at' AND '$created_at2'";
            }

            // Remove empty filters except limit and offset
            $filters = array_filter($filters, function($value, $key) {
                return ($key === 'limit' || $key === 'offset') || 
                       ($value !== null && $value !== '');
            }, ARRAY_FILTER_USE_BOTH);

            // Get orders for the store
            $orders = $this->orderManager->order()->searchByFields($filters, $perPage, $offset, "AND", $whereClauses);

            // Get total count for pagination
            $countFilters = $filters;
            unset($countFilters['limit'], $countFilters['offset']);
            $totalItems = $this->getOrdersCountByStore($store['id'], $countFilters);

            // Get dashboard statistics
            $stats = $this->orderManager->getDashboardStats($store['id'], 30);

            // Get recent activities
            $recentActivities = $this->orderManager->orderActivity()->getRecentActivities($store['id'], 10);

            // Calculate pagination
            $totalPages = (int) ceil($totalItems / $perPage);

            // Prepare order data for display
            $ordersData = [];
            foreach ($orders as $order) {
                $order = isset($order['order']) ?$order['order'] : $order;
                $fullyData = $this->orderManager->getCompleteOrder($order['id']);
                $orderData = $fullyData['order'];
                $customerData = $fullyData['customer'] ?? [];

                $ordersData[] = [
                    'id' => $orderData['id'],
                    'order_number' => $orderData['order_number'],
                    'customer_email' => $customerData['email'] ?? 'N/A',
                    'customer_name' => trim(($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? '')) ?: 'N/A',
                    'status' => $orderData['status'],
                    'payment_status' => $orderData['payment_status'],
                    'total_amount' => number_format($orderData['total_amount'], 2),
                    'currency' => $orderData['currency'],
                    'created_at' => date('Y-m-d H:i', strtotime($orderData['created_at'])),
                    'item_count' => count($fullyData['items'] ?? []),
                    'payment_count' => count($order['payments'] ?? []),
                    'adjustments' => $orderData['adjustments'] ? count($orderData['adjustments']) : 0
                ];
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return $this->renderTwig("@commerce_store/store/orders.twig", [
            'store' => $store ?? null,
            'orders' => $ordersData ?? [],
            'stats' => $stats ?? [],
            'recent_activities' => $recentActivities ?? [],
            'filters' => $filters ?? [],
            'current_page' => $page ?? 1,
            'per_page' => $perPage,
            'total_items' => $totalItems ?? 0,
            'total_pages' => $totalPages ?? 1,
            'status' => $request->query->get('status'),
            'payment_status' => $request->query->get('payment_status'),
            'search' => $request->query->get('search'),
            'date_from' => $request->query->get('date_from'),
            'date_to' => $request->query->get('date_to'),
            'success' => $success,
            'error' => $error
        ]);
    }

    /**
     * Get orders count by store for pagination
     */
    private function getOrdersCountByStore(int $storeId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM commerce_orders WHERE store_id = ?";
        $params = [$storeId];

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['payment_status'])) {
            $sql .= " AND payment_status = ?";
            $params[] = $filters['payment_status'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (order_number LIKE ? OR notes LIKE ?)";
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $result = $this->orderManager->order()->getDb()->fetch($sql, ...$params);
        return $result['count'] ?? 0;
    }

    /**
     * View a single order
     */
    public function viewOrder(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to view orders');
            }

            // Get order ID from route options
            $orderId = $request->query->get('id');
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            // Get complete order information
            $order = $this->orderManager->getCompleteOrder($orderId);
            if (!$order) {
                throw new Exception('Order not found');
            }

            // Verify order belongs to user's store
            if ($order['order']['store_id'] != $store['id']) {
                throw new Exception('Access denied');
            }

            // Get order activities
            $activities = $this->orderManager->orderActivity()->getActivityTimeline($orderId);

            return $this->renderTwig("@commerce_store/store/order_view.twig", [
                'store' => $store,
                'activities' => $activities,
                'error' => $error,
                'order' => $order,
            ]);

        } catch (Exception $e) {
            return $this->renderTwig("@commerce_store/store/order_view.twig", [
                'store' => $store ?? null,
                'order' => null,
                'activities' => [],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Edit order
     */
    public function editOrder(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $order = null;

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to edit orders');
            }

            // Get order ID from route options
            $orderId = $request->query->get('id');
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            // Handle form submission
            if ($request->isMethod('POST')) {

                $orderData = $this->processOrderEditForm($request, $orderId);
                $shipping = $request->request->all('shipping');
                $billing = $request->request->all('billing');
                $list = explode(' ', $billing['name'] ?? "");
                $customer =
                $customerData = [
                    'first_name' => $list[0] ?? '',
                    'last_name' => end($list) ?? '',
                    'billing_address_1' => $billing['address1'] ?? '',
                    'billing_address_2' => $billing['address2'] ?? '',
                    'billing_city' => $billing['city'] ?? '',
                    'billing_state' => $billing['state'] ?? '',
                    'billing_postcode' => $billing['postcode'] ?? '',
                    'billing_country' => $billing['country'] ?? '',
                    'shipping_address_1' => $shipping['address1'] ?? '',
                    'shipping_address_2' => $shipping['address2'] ?? '',
                    'shipping_city' => $shipping['city'] ?? '',
                    'shipping_state' => $shipping['state'] ?? '',
                    'shipping_postcode' => $shipping['postcode'] ?? '',
                    'shipping_country' => $shipping['country'] ?? '',
                    'phone' =>  $billing['phone'] ?? $shipping['phone'] ?? '',
                    'email' => $billing['email'] ?? $shipping['email'] ?? '',
                ];

                $cust = $this->orderManager->customer()->getCustomer($orderData['customer_id']);
                if (!empty($cust)) {
                    $customerData['user_id'] = $cust['user_id'];
                    $customerData['store_id'] = $cust['store_id'];
                    $customerData['customer_type'] = $cust['customer_type'];
                    $customerData['company'] = $cust['company'];
                    $customerData['shipping_same_as_billing'] = $shipping['same_as'] ?? 0;
                    $this->orderManager->customer()->updateCustomer($cust['id'], $customerData);
                }

                // Update order using Order class
                $updated = $this->orderManager->order()->updateOrder($orderId, $orderData);

                if ($updated) {
                    /**@var Calculator $calculator **/
                    $calculator = $this->container->get('commerce_store.calculator');
                    $calculator->reCalculateOrder($orderId);
                    $success = 'Order updated successfully!';
                    // Redirect to orders page with success message
                    return new RedirectResponse(Url::routeByName('commerce_store.orders.edit',['id'=>$orderId]));
                }
                else {
                    $error = 'Failed to update order. Please try again.';
                }
            }

            // Get complete order information
            $order = $this->orderManager->getCompleteOrder($orderId);
            if (!$order) {
                throw new Exception('Order not found');
            }

        } catch (Exception $e) {
            dd($e);
            return $this->renderTwig("@commerce_store/store/order_edit.twig", [
                'store' => $store ?? null,
                'order' => $order ?? null,
                'success' => $success,
                'error' => $e->getMessage()
            ]);
        }

        $adjustmentList = $this->container->get('commerce_store.adjustments')->getAdjustments();
        $shipping_method = [];
        foreach ($order['order']['adjustments'] ?? [] as $adjustment) {
            if ($adjustment['type'] === 'shipping') {
                $adjustment['auto_value'] = "{$adjustment['label']} ({$adjustment['source']})";
                $shipping_method = $adjustment;
            }
        }

        return $this->renderTwig("@commerce_store/store/order_edit.twig", [
            'store' => $store,
            'order' => $order,
            'success' => $success,
            'error' => $error,
            'adjustment_list' => $adjustmentList,
            'shipping' => $shipping_method,
        ]);
    }

    /**
     * Extract customer ID from form value
     */
    private function extractCustomerIdFromForm($customerValue): ?int
    {
        if (empty($customerValue)) {
            return null;
        }

        // Extract customer ID from format like "user1@example.com (1)"
        if (preg_match('/\((\d+)\)$/', $customerValue, $matches)) {
            return (int)$matches[1];
        }

        // If no parentheses found, try to extract numeric value directly
        if (is_numeric($customerValue)) {
            return (int)$customerValue;
        }

        return null;
    }

    /**
     * Process order edit form data
     * @throws \DateMalformedStringException
     * @throws DatabaseException
     */
    private function processOrderEditForm(Request $request, int $orderId): array
    {
        $userId = $this->getCurrentUserId();
        
        // Get user's store
        $store = $this->store->getStoreByUserId($userId);
        if (!$store) {
            throw new Exception('You must have a store to edit orders');
        }

        // Extract form data
        $orderData = [
            'store_id' => $store['id'],
            'customer_id' => $this->extractCustomerIdFromForm($request->request->get('customer_id')),
            'order_number' => $request->request->get('order_number'),
            'status' => $request->request->get('status', 'pending'),
            'payment_status' => $request->request->get('payment_status', 'pending'),
            'subtotal' => 0,
            'tax_amount' => (float) !empty($request->request->get('tax_amount', 0)) ? $request->request->get('tax_amount', 0) : 0,
            'shipping_amount' => (float) !empty($request->request->get('shipping_amount', 0)) ? $request->request->get('shipping_amount', 0) : 0,
            'discount_amount' => (float) !empty($request->request->get('discount_amount', 0)) ? $request->request->get('discount_amount', 0) : 0,
            'total_amount' => (float) !empty($request->request->get('total_amount', 0)) ? $request->request->get('total_amount', 0) : 0,
            'currency' => $request->request->get('currency', $this->currency->getDefaultCurrency()),
            'refund_amount' => (float) !empty($request->request->get('refund_amount', 0)) ? $request->request->get('refund_amount', 0) : 0,
            'notes' => $request->request->get('notes', ''),
            'admin_notes' => $request->request->get('admin_notes', ''),
            'updated_at' => date('Y-m-d H:i:s'),
            'item_attributes' => json_encode($request->request->all('item_attributes', [])),
        ];

        // Handle order items if submitted
        if ($request->request->has('order_items')) {
            $orderItems = $request->request->all('order_items');
           if (!empty($orderItems)) {

               if (count($orderItems['id']) === count($orderItems['quantity'])) {

                   $items = [];
                   foreach ($orderItems['id'] as $key => $itemId) {

                       $id = substr($itemId, strripos($itemId, '(') + 1, strlen($itemId));
                       $id = trim($id, ')');

                       if (is_numeric($id)) {
                           $id = (int) $id;
                           if ($product = $this->product->getProduct($id)) {
                               $item['product_id'] = $id;
                               $item['quantity'] = $orderItems['quantity'][$key];
                               $item['item_name'] = $product['name'];
                               $item['item_sku'] = $product['sku'];
                               $item['weight'] = $product['weight'];
                               $item['dimensions_length'] = $product['dimensions_length'];
                               $item['dimensions_width'] = $product['dimensions_width'];
                               $item['dimensions_height'] = $product['dimensions_height'];
                               $item['shipping_class'] = $product['shipping_class'];
                               $item['virtual'] = $product['virtual'];
                               $item['downloadable'] = $product['downloadable'];
                               $item['status']  = 'pending';
                               $item['item_attributes'] = $orderItems['item_attributes'][$key] ?? null;
                               $item['variation_id'] = null;
                               $item['notes'] = $orderItems['notes'][$key] ?? null;

                               if (!empty($product['sale_price_start_date']) && !empty($product['sale_price_end_date'])) {

                                   $start = new DateTime($product['sale_price_start_date']);
                                   $end = new DateTime($product['sale_price_end_date']);
                                   $now = new DateTime();

                                   // check if its sales period
                                   if ( !empty($product['sale_price']) && $now >= $start && $now <= $end) {
                                       $item['unit_price'] = floatval($product['sale_price']);
                                   }
                                   else {
                                       $item['unit_price'] = floatval($product['regular_price']);
                                   }

                               }
                               else {
                                   $item['unit_price'] = floatval($product['regular_price']);
                               }

                               $item['order_id'] = $request->query->get('id');
                               $items[] = $item;

                               $orderData['subtotal'] += $item['unit_price'] * $item['quantity'];

                           }

                       }
                       else {
                           $id = trim($id, 'V');
                           $product = $this->variation->getVariation($id);
                           if ($product) {
                               $item['product_id'] = $product['product_id'];
                               $item['variation_id'] = $id;
                               $item['quantity'] = $orderItems['quantity'][$key];
                               $item['item_name'] = $product['name'];
                               $item['item_sku'] = $product['sku'];
                               $item['weight'] = $product['weight'];
                               $item['dimensions_length'] = $product['dimensions_length'];
                               $item['dimensions_width'] = $product['dimensions_width'];
                               $item['dimensions_height'] = $product['dimensions_height'];
                               $item['shipping_class'] = $product['shipping_class'];
                               $item['virtual'] = $product['virtual'];
                               $item['downloadable'] = $product['downloadable'];
                               $item['status']  = 'pending';
                               $item['item_attributes'] = $orderItems['item_attributes'][$key] ?? null;
                               $item['notes'] = $orderItems['notes'][$key] ?? null;

                               if (!empty($product['sale_price_start_date']) && !empty($product['sale_price_end_date'])) {

                                   $start = new DateTime($product['sale_price_start_date']);
                                   $end = new DateTime($product['sale_price_end_date']);
                                   $now = new DateTime();

                                   // check if its sales period
                                   if ( !empty($product['sale_price']) && $now >= $start && $now <= $end) {
                                       $item['unit_price'] = floatval($product['sale_price']);
                                   }
                                   else {
                                       $item['unit_price'] = intval($product['regular_price']);
                                   }

                               }
                               else {
                                   $item['unit_price'] = floatval($product['regular_price']);
                               }

                               $item['order_id'] = $request->query->get('id');
                               $items[] = $item;
                               $orderData['subtotal'] += $item['unit_price'] * $item['quantity'];
                           }
                       }

                   }

                   $orderData['items'] = $items;
               }

           }
        }

        // Handle adjustments if submitted
        if ($request->request->has('adjustments')) {
            $adjustments = $request->request->all('adjustments');
            if (!empty($adjustments['amount'][0]) && count($adjustments['amount']) === count($adjustments['label']) && count($adjustments['label']) === count($adjustments['type'])) {
               foreach ($adjustments['amount'] as $key => $amount) {
                   if ($adjustments['type'][$key] !== 'shipping') {
                       $orderData['adjustments'][] = [
                           'amount' => $amount,
                           'label' => $adjustments['label'][$key],
                           'type' => $adjustments['type'][$key],
                           'source' => 'order'
                       ];
                   }

               }
            }
        }

        if ($request->request->has('shipping_method')) {
            $shipping_method = $request->request->get('shipping_method');
            $shipping_method = substr($shipping_method, strrpos($shipping_method, '(') + 1, strlen($shipping_method));
            $shipping_method = trim($shipping_method, ')');
            $shipping_method = !empty($shipping_method) ? $shipping_method : "free.shipping.method";
            $shippingMethod = $this->orderManager->shippingManager()->getShippingMethod($shipping_method);
            $orderData['adjustments'][] = [
                'amount' => 0,
                'label' => $shippingMethod['name'],
                'type' => 'shipping',
                'source' => $shipping_method
            ];
        }

        // Validate required fields
        if (empty($orderData['customer_id'])) {
            throw new Exception('Customer is required');
        }

        if (empty($orderData['order_number'])) {
            throw new Exception('Order number is required');
        }

        if (!is_numeric($orderData['total_amount']) || $orderData['total_amount'] < 0) {
            throw new Exception('Total amount must be a valid positive number');
        }



        return $orderData;
    }

    /**
     * Print order
     */
    public function printOrder(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to print orders');
            }

            // Get order ID from route options
            $orderId = $request->query->get('id');
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            // Get complete order information
            $order = $this->orderManager->getCompleteOrder($orderId);
            if (!$order) {
                throw new Exception('Order not found');
            }

            // Verify order belongs to user's store
            if ($order['order']['store_id'] != $store['id']) {
                throw new Exception('Access denied');
            }

            return $this->renderTwig("@commerce_store/store/order_print.twig", [
                'store' => $store,
                'order' => $order
            ]);

        } catch (Exception $e) {
            return new Response('Error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Generate invoice
     */
    public function generateInvoice(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to generate invoices');
            }

            // Get order ID from route options
            $orderId = $request->query->get('id');
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            // Get complete order information
            $order = $this->orderManager->getCompleteOrder($orderId);
            if (!$order) {
                throw new Exception('Order not found');
            }

            // Verify order belongs to user's store
            if ($order['order']['store_id'] != $store['id']) {
                throw new Exception('Access denied');
            }

            $content = $this->renderTwig("@commerce_store/store/order_invoice.twig", [
                'store' => $store,
                'order' => $order
            ]);

            $mpdf = new \Mpdf\Mpdf(['tempDir' => $_ENV['ROOT']. DIRECTORY_SEPARATOR . 'tmp']);
            $html = $content->getContent();
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
            $html = trim($html);

            if (!empty($html)) {
                $mpdf->WriteHTML($html);

            }
            $save_path = $_ENV['PUBLIC_STREAM_DIR'] .DIRECTORY_SEPARATOR . 'invoices';
            @mkdir($save_path, 0777, true);
            $save_path .= DIRECTORY_SEPARATOR . $order['order']['order_number'] . ".pdf";

            $mpdf->Output($save_path, 'F');


            /**@var MailManager $mailManager **/
            $mailManager = $this->container->get('mail.manager');
            $mailManager->sendWithAttachment(
                $order['customer']['email'],
                "Invoice for order #{$order['order']['order_number']}",
                $content->getContent(),
                $save_path,
                "Invoice for order #{$order['order']['order_number']} PDF attachment"
            );

            return new Response(file_get_contents($save_path), 200, ['Content-Type' => 'application/pdf']);

        } catch (Exception $e) {

            return new Response('Error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Export orders
     */
    public function exportOrders(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to export orders');
            }

            // Get filters from request
            $page = max(1, (int) $request->query->get('page', 1));
            $perPage = 1000;
            $offset = ($page - 1) * $perPage;

            $filters = [
                'status' => $request->query->get('status'),
                'payment_status' => $request->query->get('payment_status'),
                'order_number' => $request->query->get('search'),
                'store_id'   =>  $store['id'],
            ];

            $created_at = $request->query->get('date_from');
            $created_at2 = $request->query->get('date_to');
            $whereClauses = "";
            if (!empty($created_at) && !empty($created_at2)) {
                $whereClauses = "created_at BETWEEN '$created_at' AND '$created_at2'";
            }

            // Remove empty filters except limit and offset
            $filters = array_filter($filters, function($value, $key) {
                return ($key === 'limit' || $key === 'offset') ||
                    ($value !== null && $value !== '');
            }, ARRAY_FILTER_USE_BOTH);

            // Get orders for the store
            $orders = $this->orderManager->order()->searchByFields($filters, $perPage, $offset, "AND", $whereClauses);

            // Generate CSV
            $csv = $this->orderManager->exportOrdersToCSV($orders);

            // Return CSV download
            return new Response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="orders_export_' . date('Y-m-d') . '.csv"'
            ]);

        } catch (Exception $e) {
            return new Response('Error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cancel order
     */
    public function cancelOrder(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to cancel orders');
            }

            // Get order ID and reason from request
            $orderId = $request->request->get('order_id');
            $reason = $request->request->get('reason', '');

            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            // Get order information
            $order = $this->orderManager->order()->getOrder($orderId);
            if (!$order) {
                throw new Exception('Order not found');
            }

            // Verify order belongs to user's store
            if ($order['store_id'] != $store['id']) {
                throw new Exception('Access denied');
            }

            // Cancel order with refund
            $success = $this->orderManager->cancelOrderWithRefund($orderId, $reason);
            if ($success) {
                $success = "Order cancelled successfully!";
            }

            // Handle AJAX requests
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => $success,
                    'error' => $error
                ]);
            }

            // Redirect back to orders page
            if ($success) {
                return $this->redirect(Url::routeByName('commerce_store.orders') . '?success=' . urlencode($success));
            }

            // If error, show orders page with error
            return $this->orders($request, $route_name, $options);

        } catch (Exception $e) {
            $error = $e->getMessage();
            
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $error
                ], 400);
            }
        }

        // Redirect back to orders page
        return $this->redirect(Url::routeByName('commerce_store.orders') . '?error=' . urlencode($error));
    }

    /**
     * Process order form data
     */
    protected function processOrderForm(Request $request): array
    {
        $data = [];
        
        // Order Information
        $data['status'] = $request->request->get('status');
        $data['payment_status'] = $request->request->get('payment_status');
        
        // Financial Information
        $data['subtotal'] = $request->request->get('subtotal');
        $data['tax_amount'] = $request->request->get('tax_amount');
        $data['shipping_amount'] = $request->request->get('shipping_amount');
        $data['discount_amount'] = $request->request->get('discount_amount');
        $data['total_amount'] = $request->request->get('total_amount');
        
        // Notes
        $data['notes'] = $request->request->get('notes');
        $data['admin_notes'] = $request->request->get('admin_notes');
        
        // Filter out empty values
        return array_filter($data, function($value) {
            if (is_string($value)) {
                return trim($value) !== '';
            }
            return $value !== null;
        });
    }

    /**
     * Update order (helper method)
     */
    private function updateOrder(int $orderId, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['id'] = $orderId;

        $sql = "UPDATE commerce_orders SET 
            status = :status, payment_status = :payment_status, subtotal = :subtotal,
            tax_amount = :tax_amount, shipping_amount = :shipping_amount,
            discount_amount = :discount_amount, total_amount = :total_amount,
            notes = :notes, admin_notes = :admin_notes, updated_at = :updated_at
        WHERE id = :id";

        $this->orderManager->order()->getDb()->query($sql, ...$data);
        return true;
    }

    /**
     * Create new order
     */
    public function createOrder(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;
        $orderData = null;
        $customers = [];
        $products = [];

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to create orders');
            }

            // Handle form submission
            if ($request->isMethod('POST')) {
                $orderData = $this->processOrderCreateForm($request);

                // Create the order using the Order class
                $orderId = $this->orderManager->order()->createOrder($orderData);
                
                if ($orderId) {
                    // Redirect to orders page with success message
                    return new RedirectResponse(Url::routeByName('commerce_store.orders.edit',['id' => $orderId]));
                } else {
                    $error = 'Failed to create order. Please try again.';
                }
            }

            // Get customers for dropdown
            $customers = $this->orderManager->customer()->getCustomersByStore($store['id'], 100);

            // Get products for dropdown
            $products = $this->product->getProductsByStore($store['id'], ['limit' => 100]);

            return $this->renderTwig("@commerce_store/store/order_create.twig", [
                'store' => $store,
                'customers' => $customers,
                'products' => $products,
                'order_data' => $orderData,
                'success' => $success,
                'error' => $error
            ]);

        } catch (Exception $e) {
            return $this->renderTwig("@commerce_store/store/order_create.twig", [
                'store' => $store ?? null,
                'customers' => $customers ?? [],
                'products' => $products ?? [],
                'order_data' => $orderData ?? null,
                'success' => $success,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process order create form data
     */
    private function processOrderCreateForm(Request $request): array
    {
        $userId = $this->getCurrentUserId();
        
        // Get user's store
        $store = $this->store->getStoreByUserId($userId);
        if (!$store) {
            throw new Exception('You must have a store to create orders');
        }

        // Get customer ID from form (this is the user ID from autocomplete)
        $customerIdFromForm = $request->request->get('customer_id');
        $customerIdFromForm = substr($customerIdFromForm,  strrpos($customerIdFromForm, '(') , strlen($customerIdFromForm));
        $customerIdFromForm = trim($customerIdFromForm, '()');
        
        // Determine actual customer ID from user ID
        $customer = $this->orderManager->customer()->getCustomerByUser((int)$customerIdFromForm);
        
        if (empty($customer)) {
            // Create new customer for this user
            $customerData = [
                'store_id' => $store['id'],
                'user_id' => (int)$customerIdFromForm,
                'first_name' => 'Customer',
                'last_name' => 'User ' . $customerIdFromForm,
                'email' => 'user' . $customerIdFromForm . '@example.com', // Temporary email
                'phone' => '',
                'company' => '',
                'customer_type' => 'registered',
                'billing_address_1' => '',
                'billing_address_2' => '',
                'billing_city' => '',
                'billing_state' => '',
                'billing_postcode' => '',
                'billing_country' => '',
                'shipping_same_as_billing' => 1,
                'shipping_address_1' => '',
                'shipping_address_2' => '',
                'shipping_city' => '',
                'shipping_state' => '',
                'shipping_postcode' => '',
                'shipping_country' => '',
                'total_orders' => 0,
                'total_spent' => 0
            ];
            
            $newCustomerId = $this->orderManager->customer()->createCustomer($customerData);
            if (!$newCustomerId) {
                throw new Exception('Failed to create customer for user');
            }
            
            $actualCustomerId = $newCustomerId;
        } else {
            $actualCustomerId = $customer['id'];
        }
        
        if (!$actualCustomerId) {
            throw new Exception('Invalid customer selected');
        }

        // Extract form data
        $orderData = [
            'store_id' => $store['id'],
            'customer_id' => $actualCustomerId,
            'order_number' => $request->request->get('order_number'),
            'status' => $request->request->get('status', 'pending'),
            'subtotal' => $request->request->get('subtotal', 0),
            'tax_amount' => $request->request->get('tax_amount', 0),
            'shipping_amount' => $request->request->get('shipping_amount', 0),
            'total_amount' => $request->request->get('total_amount', 0),
            'currency' => $request->request->get('currency', 'USD'),
            'notes' => $request->request->get('notes', ''),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Validate required fields
        if (empty($orderData['customer_id'])) {
            throw new Exception('Customer is required');
        }

        if (empty($orderData['order_number'])) {
            throw new Exception('Order number is required');
        }

        if (!is_numeric($orderData['total_amount']) || $orderData['total_amount'] < 0) {
            throw new Exception('Total amount must be a valid positive number');
        }

        return $orderData;
    }

    public function payments(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        $success = null;

        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to view payments');
            }

            // Get order ID from route options
            $orderId = $request->query->get('id');
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            // Verify order belongs to user's store
            $order = $this->orderManager->order()->getOrder($orderId);
            if (!$order || $order['store_id'] != $store['id']) {
                throw new Exception('Order not found or access denied');
            }

            // Get payments for this order
            $payments = $this->orderManager->payment()->getPaymentsByOrder($orderId);
            $order['customer'] = $this->orderManager->customer()->getCustomer($order['customer_id']);

            return $this->renderTwig("@commerce_store/store/payments.twig", [
                'store' => $store,
                'order' => $order,
                'payments' => $payments,
                'success' => $success,
                'error' => $error
            ]);

        } catch (Exception $e) {
            return $this->renderTwig("@commerce_store/store/payments.twig", [
                'store' => $store ?? null,
                'order' => null,
                'payments' => [],
                'success' => $success,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function paymentOptions(Request $request, string $route_name, array $options): Response
    {
        $userId = $this->getCurrentUserId();
        
        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to view payment options');
            }

            // Get payment manager
            $paymentManager = $this->container->get('commerce_store.payment.manager');
            
            // Get available payment gateways
            $paymentOptions = $paymentManager->getGateways();
            
            // Get order ID if provided
            $orderId = $request->query->get('id');
            $order = null;
            if ($orderId) {
                $order = $this->orderManager->order()->getOrder($orderId);
                if (!$order || $order['store_id'] != $store['id']) {
                    throw new Exception('Order not found or access denied');
                }
            }

            return $this->renderTwig("@commerce_store/store/payment_options.twig", [
                'store' => $store,
                'order' => $order,
                'payment_options' => $paymentOptions,
                'error' => null
            ]);

        } catch (Exception $e) {
            return $this->renderTwig("@commerce_store/store/payment_options.twig", [
                'store' => $store ?? null,
                'order' => null,
                'payment_options' => [],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function paymentOptionPayment(Request $request, string $route_name, array $options): Response|RedirectResponse
    {
        $userId = $this->getCurrentUserId();
        $error = null;
        
        try {
            // Get user's store
            $store = $this->store->getStoreByUserId($userId);
            if (!$store) {
                throw new Exception('You must have a store to process payments');
            }

            // Get order ID from route options
            $orderId = $request->query->get('id');
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            // Verify order belongs to user's store
            $order = $this->orderManager->order()->getOrder($orderId);
            if (!$order || $order['store_id'] != $store['id']) {
                throw new Exception('Order not found or access denied');
            }

            $order['customer'] = $this->orderManager->customer()->getCustomer($order['customer_id']);
            // Get payment method from query
            $method = $request->query->get('method');
            if (!$method) {
                throw new Exception('Payment method is required');
            }

            // Get payment manager and gateway
            $paymentManager = $this->container->get('commerce_store.payment.manager');

            /**@var PaymentGatewayInterface $paymentGateway **/
            $paymentGateway = $paymentManager->getGateway($method);
            
            if (!$paymentGateway) {
                throw new Exception('Invalid payment gateway');
            }

            // Build payment form
            $form = [];
            $form['order_id'] = [
                'type' => 'hidden',
                'name' => 'order_id',
                'value' => $orderId,
            ];
            $form['gateway_id'] = [
                'type' => 'hidden',
                'name' => 'gateway_id',
                'value' => $paymentGateway->gatewayId,
            ];
            
            $formState = new FormState();
            $form = $paymentGateway->getPaymentForm($form, $formState);

            // Build form render
            $formBuilder = new FormBuilder();
            $formBuilder = $formBuilder->buildFormRender($form, $formState, $request);

            if ($request->isMethod('POST')) {
                $formState->buildFormState($form, $request);
                $payment = new Payment($this->container->get('database'), $this->container->get('logger'));
                $paymentGateway->processForm($form, $formState, $order, $payment);
                /**@var Calculator $culculator**/
                $calculator = $this->container->get('commerce_store.calculator');
                $calculator->reCalculateOrder($orderId);
                $listError = $formState->getErrors();
                foreach ($listError as $erro) {
                    if (is_string($erro)) {
                        $error .= "<p>$erro</p>";
                    }
                }

                if (empty($listError)) {
                    return $this->redirect(Url::routeByName("commerce_store.order.payments",['id'=> $orderId]));
                }
            };

            return $this->renderTwig("@commerce_store/store/payment_form.twig", [
                'store' => $store,
                'order' => $order,
                'gateway' => $paymentGateway,
                'form' => $formBuilder,
                'error' => $error
            ]);

        } catch (Exception $e) {
            return $this->renderTwig("@commerce_store/store/payment_form.twig", [
                'store' => $store ?? null,
                'order' => null,
                'gateway' => null,
                'form' => null,
                'error' => $e->getMessage()
            ]);
        }
    }


    /**
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws MpdfException
     */
    public function checkoutWorkFlow(Request $request, string $route_name, array $options): Response
    {

        /**@var CheckoutManager $checkoutWorkFlow **/
        $checkoutWorkFlow = $this->container->get('commerce_store.checkout.manager');

        $step = $request->query->get('step', 'checkout-review');
        $id   = $request->query->get('id');

        $order = $this->orderManager->order()->getOrder($id);
        if ($order['status'] !== 'pending') {
            return $this->redirect('/');
        }

        $checkoutWorkFlowStepHandlers = $checkoutWorkFlow->getStepWorkFlows($step);

        $handlerObjects = [];
        foreach ($checkoutWorkFlowStepHandlers as $handler) {
            $handlerObjects[] = $checkoutWorkFlow->constructWorkFlowHandlerObject($handler,$this->container, $id, $step);
        }

        /**@var array<CheckoutWorkflowInterface> $handlerObjects **/
        $handlerObjects = array_filter($handlerObjects);

        $htmlFormFields = "";

        foreach ($handlerObjects as $handlerObject) {
            $htmlFormFields .= $handlerObject->buildCheckoutStepFormFields($request);
        }

        $orderDraft = new OrderDraft();

        if ($request->isMethod('POST')) {
            foreach ($handlerObjects as $handlerObject) {
                $orderDraft = $handlerObject->processCheckoutStepFormFields($request);
            }

            $step = $request->query->get('step');
            if (array_key_last($checkoutWorkFlow->getCheckoutSteps()) === $step) {
                $order = $this->orderManager->order()->getOrder($request->query->get('id'));
                $order['customer'] = $this->orderManager->customer()->getCustomer($order['customer_id']);
                if ($order) {
                    if ($this->orderManager->order()->updateOrderStatus($order['id'], 'processing')) {
                        $store = $this->store->getStore($order['store_id']);
                        $content = $this->renderTwig("@commerce_store/store/order_invoice.twig", [
                            'store' => $store,
                            'order' => $order
                        ]);

                        $mpdf = new \Mpdf\Mpdf(['tempDir' => $_ENV['ROOT']. DIRECTORY_SEPARATOR . 'tmp']);
                        $html = $content->getContent();
                        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
                        $html = trim($html);

                        if (!empty($html)) {
                            $mpdf->WriteHTML($html);

                        }
                        $save_path = $_ENV['PUBLIC_STREAM_DIR'] .DIRECTORY_SEPARATOR . 'invoices';
                        @mkdir($save_path, 0777, true);
                        $save_path .= DIRECTORY_SEPARATOR . $order['order_number'] . ".pdf";

                        $mpdf->Output($save_path, 'F');

                        /**@var MailManager $mailManager **/
                        $mailManager = $this->container->get('mail.manager');
                        $mailManager->sendWithAttachment(
                            'nyasuluchance6@gmail.com',//$order['customer']['email'],
                            "Invoice for order #{$order['order_number']}",
                            $content->getContent(),
                            $save_path,
                            "Invoice for order #{$order['order_number']} PDF attachment"
                        );
                        return $this->redirect("/");
                    }
                }
            }
        }

        return $this->renderTwig("@commerce_store/store/checkout_workflow.twig", [
            'step' => $step,
            'id'   => $id,
            'fieldsHtml'  => $htmlFormFields,
            'steps'  => $checkoutWorkFlow->getCheckoutSteps(),
            'stepsCount'  => count($checkoutWorkFlow->getCheckoutSteps()),
            'order'       => $this->orderManager->order()->getOrder($id),
            'cartOptions' => ['steps' => $checkoutWorkFlow->getCheckoutSteps(), 'id'=> $id, 'step'=> $step, 'stepCount'=> count($checkoutWorkFlow->getCheckoutSteps())],
            'order_draft' => $orderDraft,
            ]);
    }

    public function shopping(Request $request, string $route_name, array $options): Response
    {
        return new Response("");
    }


    public function autoSaveOrderItems(Request $request, string $route_name, array $options): JsonResponse
    {
        $items = json_decode($request->getContent(), true);

        if (!empty($items)) {

            $order = [];
            foreach ($items as $item) {
                $this->orderManager->orderItem()->updateOrderItemQuantityCount($item['id'], $item['qty']);
                $order[] = $this->orderManager->orderItem()->getOrderItem($item['id']);
            }

            $order = array_column($order, 'order_id');
            $order = array_unique($order);

            if (!empty($order)) {
                $id = reset($order);
                if (is_numeric($id)) {
                    $this->container->get('commerce_store.calculator')->reCalculateOrder($id);
                }
            }
        }
        return new JsonResponse(['status'=> true]);
    }

    public function summaryUpdate(Request $request, string $route_name, array $options): JsonResponse
    {
        $orderId = $request->query->get('id');
        if (empty($orderId)) {
            return new JsonResponse(['status' => false]);
        }

        $order = $this->orderManager->order()->getOrder($orderId);

        return new JsonResponse(['status' => true, 'order' => $order]);
    }

    public function paymentFormBuild(Request $request, string $route_name, array $options): JsonResponse
    {
        /**@var PaymentManager $paymentManager **/
        $paymentManager = $this->container->get('commerce_store.payment.manager');
        $paymentGatewayId = $request->query->get('id');

        if (empty($paymentGatewayId)) {
            return new JsonResponse(['status' => false]);
        }

        $paymentGateway = $paymentManager->getGateway($paymentGatewayId);

        if (empty($paymentGateway)) {
            return new JsonResponse(['status' => false]);
        }

        $formFields = [];
        $formState = new FormState();
        $form = $paymentGateway->getPaymentForm($formFields, $formState);
        $formState->buildFormState($form, $request);
        $formBuilder = new FormBuilder();
        $form = $formBuilder->buildFormRender($form, $formState, $request);

        return new JsonResponse(['status' => true, 'html' => $form->__toString()]);
    }
}
