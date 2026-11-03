<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Form;

use Simp\Multiform\lib\Helper;
use Simp\Multiform\lib\wrapper\MultiStepFormStyles;
use Simp\Multiform\lib\wrapper\MultiStepFormWrapper;
use Simp\Adjustment\lib\Adjustment;
use Simp\Adjustment\lib\Adjustments;
use Simp\Pindrop\Routing\Url;
use Simp\Price\lib\Price;
use Symfony\Component\HttpFoundation\Request;

final class StockflowFormFactory
{
    private const PRODUCT_WIZARD_KEY = 'stockflow-product-create';
    private const MANUAL_ORDER_WIZARD_KEY = 'stockflow-manual-order-v3';
    private const LEGACY_MANUAL_ORDER_WIZARD_KEY_V2 = 'stockflow-manual-order-v2';
    private const LEGACY_MANUAL_ORDER_WIZARD_KEY = 'stockflow-manual-order';

    public function __construct(private readonly FormRenderer $renderer)
    {
    }

    public function inventoryAdjustment(Request $request, array $variants): string
    {
        $options = [];
        foreach ($variants as $variant) {
            $options[$variant['id']] = $variant['product_name'] . ' — ' . $variant['sku']
                . ($variant['option_label'] ? ' (' . $variant['option_label'] . ')' : '');
        }

        return $this->renderer->render($request, '/stockflow/inventory/adjust', [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'adjust_inventory'],
            ['name' => 'variant_id', 'type' => 'select', 'label' => 'Product variant', 'required' => true, 'options' => $options],
            ['name' => 'quantity_delta', 'type' => 'number', 'label' => 'Quantity change', 'required' => true, 'placeholder' => 'Positive or negative'],
            ['name' => 'movement_type', 'type' => 'select', 'label' => 'Movement type', 'required' => true, 'options' => [
                'receipt' => 'Receipt',
                'adjustment' => 'Adjustment',
                'return' => 'Return',
                'damage' => 'Damage',
                'sale' => 'Sale',
            ]],
            ['name' => 'reason', 'type' => 'text', 'label' => 'Reason', 'required' => true, 'placeholder' => 'Why is stock changing?'],
        ]);
    }

    public function productForms(
        Request $request,
        array $categories,
        string $currency
    ): array {
        $store = new PhpSessionFormStateStore();
        $hasSavedState = $store->load(self::PRODUCT_WIZARD_KEY) !== [];

        return [
            'wizard_html' => $this->renderProductWizard($request, $categories, $currency),
            'has_wizard_state' => $hasSavedState,
        ];
    }

    public function processProductWizard(Request $request, array $categories, string $currency): array
    {
        $wizard = $this->createProductWizard($request, $categories, $currency);
        $wizard->processSubmission(self::PRODUCT_WIZARD_KEY, $request->request->all());

        return [
            'complete' => $wizard->isComplete(self::PRODUCT_WIZARD_KEY),
            'values' => $wizard->getValues(self::PRODUCT_WIZARD_KEY),
            'html' => $this->renderWizardStep($wizard, $request, $categories),
        ];
    }

    public function renderProductWizard(Request $request, array $categories, string $currency): string
    {
        return $this->renderWizardStep(
            $this->createProductWizard($request, $categories, $currency),
            $request,
            $categories
        );
    }

    public function clearProductWizard(): void
    {
        (new PhpSessionFormStateStore())->clear(self::PRODUCT_WIZARD_KEY);
    }

    public function resetProductWizardCompletion(): void
    {
        $store = new PhpSessionFormStateStore();
        $values = $store->load(self::PRODUCT_WIZARD_KEY);
        unset($values['_multistep_complete']);
        $store->save(self::PRODUCT_WIZARD_KEY, $values);
    }

    public function productEdit(Request $request, array $product, array $categories): string
    {
        $categoryOptions = ['' => 'Uncategorized'];
        foreach ($categories as $category) {
            $categoryOptions[$category['id']] = $category['name'];
        }

        return $this->renderer->render($request, '/stockflow/products/edit?id=' . (int) $product['id'], [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'update_product'],
            ['name' => 'category_id', 'type' => 'select', 'label' => 'Category', 'value' => $product['category_id'], 'options' => $categoryOptions],
            ['name' => 'name', 'type' => 'text', 'label' => 'Product name', 'required' => true, 'value' => $product['name']],
            ['name' => 'slug', 'type' => 'text', 'label' => 'URL slug', 'required' => true, 'value' => $product['slug']],
            ['name' => 'brand', 'type' => 'text', 'label' => 'Brand', 'value' => $product['brand']],
            ['name' => 'description', 'type' => 'textarea', 'label' => 'Description', 'value' => $product['description']],
            ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'value' => $product['status'], 'options' => [
                'draft' => 'Draft',
                'active' => 'Active',
                'archived' => 'Archived',
            ]],
            ['name' => 'featured', 'type' => 'select', 'label' => 'Featured product', 'value' => (int) $product['featured'] === 1 ? 'yes' : 'no', 'options' => [
                'no' => 'No',
                'yes' => 'Yes',
            ]],
        ], 'post', 'Save product');
    }

    public function addVariant(Request $request, int $productId, string $currency): string
    {
        return $this->renderer->render($request, '/stockflow/products/variant/add?product_id=' . $productId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'create_variant'],
            ['name' => 'product_id', 'type' => 'hidden', 'value' => $productId],
            ['name' => 'option_label', 'type' => 'text', 'label' => 'Variant title', 'placeholder' => 'Size / color / pack'],
            ['name' => 'sku', 'type' => 'text', 'label' => 'SKU', 'required' => true],
            ['name' => 'barcode', 'type' => 'text', 'label' => 'Barcode / UPC'],
            ['name' => 'price', 'type' => 'text', 'label' => 'Price (' . $currency . ')', 'required' => true, 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'cost_price', 'type' => 'text', 'label' => 'Cost price', 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'compare_at_price', 'type' => 'text', 'label' => 'Compare-at price (' . $currency . ')', 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'weight_grams', 'type' => 'number', 'label' => 'Weight (grams)', 'attributes' => ['min' => '0']],
            ['name' => 'quantity', 'type' => 'number', 'label' => 'Starting quantity', 'value' => '0'],
            ['name' => 'reorder_point', 'type' => 'number', 'label' => 'Reorder point', 'value' => '0'],
        ], 'post', 'Add variant');
    }

    public function deleteProduct(Request $request, int $productId): string
    {
        return $this->renderer->render($request, '/stockflow/products/delete?id=' . $productId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'delete_product'],
        ], 'post', 'Delete product');
    }

    public function deleteVariant(Request $request, int $variantId): string
    {
        return $this->renderer->render($request, '/stockflow/products/variant/delete?id=' . $variantId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'delete_variant'],
        ], 'post', 'Delete variant');
    }

    public function variantEdit(Request $request, array $variant, string $currency): string
    {
        return $this->renderer->render($request, '/stockflow/products/variant/edit?id=' . (int) $variant['id'], [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'update_variant'],
            ['name' => 'sku', 'type' => 'text', 'label' => 'SKU', 'required' => true, 'value' => $variant['sku']],
            ['name' => 'barcode', 'type' => 'text', 'label' => 'Barcode / UPC', 'value' => $variant['barcode']],
            ['name' => 'option_label', 'type' => 'text', 'label' => 'Variant title', 'value' => $variant['option_label']],
            ['name' => 'price', 'type' => 'text', 'label' => 'Price (' . $currency . ')', 'required' => true, 'value' => (string) $variant['price'], 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'cost_price', 'type' => 'text', 'label' => 'Cost price', 'value' => $variant['cost_price'] === null ? '' : (string) $variant['cost_price'], 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'compare_at_price', 'type' => 'text', 'label' => 'Compare-at price (' . $currency . ')', 'value' => $variant['compare_at_price'] === null ? '' : (string) $variant['compare_at_price'], 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'weight_grams', 'type' => 'number', 'label' => 'Weight (grams)', 'value' => $variant['weight_grams'] === null ? '' : (string) $variant['weight_grams'], 'attributes' => ['min' => '0']],
            ['name' => 'reorder_point', 'type' => 'number', 'label' => 'Reorder point', 'value' => (string) ($variant['reorder_point'] ?? 0)],
            ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'value' => $variant['status'], 'options' => [
                'active' => 'Active',
                'archived' => 'Archived',
            ]],
        ], 'post', 'Save variant');
    }

    private function createProductWizard(
        Request $request,
        array $categories,
        string $currency
    ): MultiStepFormWrapper {
        $categoryOptions = ['new' => 'Create a new category'];
        foreach ($categories as $category) {
            $categoryOptions[$category['id']] = $category['name'];
        }

        $reviewFields = [];
        foreach ([
            'review_name' => 'Product name',
            'review_sku' => 'First variant SKU',
            'review_price' => 'Price (' . $currency . ')',
            'review_compare_at_price' => 'Compare-at price (' . $currency . ')',
            'review_weight_grams' => 'Weight (grams)',
            'review_quantity' => 'Starting quantity',
        ] as $name => $label) {
            $field = new NamedInputField('text', $name);
            $field->setLabel($label);
            $field->setReadonly(true);
            $reviewFields[$name] = $field;
        }

        $wizard = new MultiStepFormWrapper(
            new PhpSessionFormStateStore(),
            new MultiStepFormStyles('icon-grid-cards')
        );
        $wizard->setAttribute('id', 'stockflow-product-wizard');
        $wizard->setAttribute('class', 'stockflow-product-wizard');
        $wizard->setAction('/stockflow/products/manage');
        $wizard->setAttribute('enctype', 'multipart/form-data');
        $wizard->addStep('Product details', $this->fields($request, [
            ['name' => 'name', 'type' => 'text', 'label' => 'Product name', 'required' => true],
            ['name' => 'slug', 'type' => 'text', 'label' => 'URL slug', 'placeholder' => 'Generated from product name if empty'],
            ['name' => 'brand', 'type' => 'text', 'label' => 'Brand'],
            ['name' => 'description', 'type' => 'textarea', 'label' => 'Description'],
            ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'options' => [
                'draft' => 'Draft',
                'active' => 'Active',
                'archived' => 'Archived',
            ]],
            ['name' => 'featured', 'type' => 'select', 'label' => 'Featured product', 'value' => 'no', 'options' => [
                'no' => 'No',
                'yes' => 'Yes',
            ]],
        ]), '✦');
        $wizard->addStep('Category', $this->fields($request, [
            ['name' => 'category_id', 'type' => 'select', 'label' => 'Existing category', 'options' => $categoryOptions],
            ['name' => 'category_name', 'type' => 'text', 'label' => 'New category name'],
            ['name' => 'category_slug', 'type' => 'text', 'label' => 'New category URL slug', 'placeholder' => 'Generated from category name if empty'],
            ['name' => 'category_description', 'type' => 'textarea', 'label' => 'New category description'],
        ]), '◇');
        $wizard->addStep('First variant & stock', $this->fields($request, [
            ['name' => 'option_label', 'type' => 'text', 'label' => 'Variant title', 'placeholder' => 'Size / color / pack'],
            ['name' => 'sku', 'type' => 'text', 'label' => 'SKU', 'required' => true],
            ['name' => 'barcode', 'type' => 'text', 'label' => 'Barcode / UPC'],
            ['name' => 'price', 'type' => 'text', 'label' => 'Price (' . $currency . ')', 'required' => true, 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'cost_price', 'type' => 'text', 'label' => 'Cost price', 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'compare_at_price', 'type' => 'text', 'label' => 'Compare-at price (' . $currency . ')', 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'weight_grams', 'type' => 'number', 'label' => 'Weight (grams)', 'attributes' => ['min' => '0']],
            ['name' => 'quantity', 'type' => 'number', 'label' => 'Starting quantity', 'value' => '0'],
            ['name' => 'reorder_point', 'type' => 'number', 'label' => 'Reorder point', 'value' => '0'],
        ]), '◎');
        $reviewFields['review_category'] = new NamedInputField('text', 'review_category');
        $reviewFields['review_category']->setLabel('Category');
        $reviewFields['review_category']->setReadonly(true);
        $wizard->addStep('Review product', array_merge(
            $this->fields($request, [
                ['name' => 'product_image', 'type' => 'file', 'label' => 'Product image', 'attributes' => ['accept' => 'image/jpeg,image/png,image/webp,image/gif']],
            ]),
            array_values($reviewFields)
        ), '✓');

        return $wizard;
    }

    private function fields(Request $request, array $definitions): array
    {
        $fields = [];
        foreach ($definitions as $definition) {
            $type = $definition['type'] ?? 'text';
            $name = $definition['name'];
            $field = in_array($type, ['select', 'textarea'], true)
                ? new NamedElementField($type, $name)
                : new NamedInputField($type, $name);
            $field->setLabel($definition['label'] ?? '');
            $field->setRequired($definition['required'] ?? false);
            $field->setValue($definition['value'] ?? null);
            $field->setPlaceholder($definition['placeholder'] ?? '');
            if (isset($definition['options'])) {
                $field->setList($definition['options']);
            }
            foreach ($definition['attributes'] ?? [] as $attribute => $value) {
                $field->setAttribute((string) $attribute, $value);
            }
            if (!empty($definition['payment_provider'])) {
                $field->setAttribute('data-payment-provider', $definition['payment_provider']);
                $field->setAttribute('data-payment-required', !empty($definition['payment_required']));
            }
            $fields[] = $field;
        }

        if (!empty($_ENV['CSRF_TOKEN_SECRET'])) {
            $csrf = new NamedInputField('hidden', '_csrf_token');
            $csrf->setValue(Url::generateToken($request, $_ENV['CSRF_TOKEN_SECRET']));
            $fields[] = $csrf;
        }

        return $fields;
    }

    private function renderWizardStep(
        MultiStepFormWrapper $wizard,
        Request $request,
        array $categories
    ): string
    {
        $values = $wizard->getValues(self::PRODUCT_WIZARD_KEY);
        $step = $wizard->getCurrentStep(self::PRODUCT_WIZARD_KEY);
        $categoryName = '';
        if (!empty($values['category_id'])) {
            foreach ($categories as $category) {
                if ((string) $category['id'] === (string) ($values['category_id'] ?? '')) {
                    $categoryName = $category['name'];
                    break;
                }
            }
        } else {
            $categoryName = $values['category_name'] ?? '';
        }
        $review = [
            'review_name' => $values['name'] ?? '',
            'review_category' => $categoryName,
            'review_sku' => $values['sku'] ?? '',
            'review_price' => $values['price'] ?? '',
            'review_compare_at_price' => $values['compare_at_price'] ?? '',
            'review_weight_grams' => $values['weight_grams'] ?? '',
            'review_quantity' => $values['quantity'] ?? '0',
        ];

        $rendered = $wizard->render(self::PRODUCT_WIZARD_KEY, $step, static function ($field) use ($values, $review): string {
            if (array_key_exists($field->getName(), $review)) {
                $field->setValue($review[$field->getName()]);
            }
            $markup = Helper::renderField($field, $values);
            return $field->getType() === 'hidden'
                ? $markup
                : '<div class="form-field">' . $markup . '</div>';
        });

        return $rendered;
    }

    public function orderFilter(Request $request, array $filters): string
    {
        return $this->renderer->render($request, '/stockflow/orders', [
            ['name' => 'status', 'type' => 'select', 'label' => 'Order status', 'value' => $filters['status'], 'options' => [
                '' => 'All statuses',
                'pending' => 'Pending',
                'confirmed' => 'Confirmed',
                'processing' => 'Processing',
                'shipped' => 'Shipped',
                'delivered' => 'Delivered',
                'cancelled' => 'Cancelled',
                'refunded' => 'Refunded',
            ]],
            ['name' => 'email', 'type' => 'email', 'label' => 'Customer email', 'value' => $filters['email']],
            ['name' => 'from', 'type' => 'date', 'label' => 'From date', 'value' => $filters['from']],
            ['name' => 'to', 'type' => 'date', 'label' => 'To date', 'value' => $filters['to']],
        ], 'get', 'Filter orders');
    }

    public function orderEdit(
        Request $request,
        array $order,
        array $variants,
        array $shippingMethods,
        array $taxRules,
        array $discountRules
    ): string {
        $variantOptions = ['' => 'Choose a product variant'];
        foreach ($variants as $variant) {
            $variantOptions[(string) $variant['id']] = $variant['product_name'] . ' — ' . $variant['sku'];
        }
        $shippingOptions = [];
        foreach ($shippingMethods as $method) {
            if (($method['status'] ?? '') === 'active') {
                $shippingOptions[(string) $method['id']] = (string) $method['name'];
            }
        }
        $itemTaxOptions = [];
        $orderTaxOptions = [];
        foreach ($taxRules as $rule) {
            $id = (string) ($rule['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (in_array($rule['apply_to'] ?? 'legacy', ['product', 'all_products', 'legacy'], true)) {
                $itemTaxOptions[$id] = (string) $rule['name'];
            }
            if (in_array($rule['apply_to'] ?? 'legacy', ['order_subtotal', 'legacy'], true)) {
                $orderTaxOptions[$id] = (string) $rule['name'];
            }
        }
        $itemDiscountOptions = ['' => 'No item discount'];
        $orderDiscountOptions = ['' => 'No order discount'];
        foreach ($discountRules as $rule) {
            $id = (string) ($rule['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (in_array($rule['apply_to'] ?? 'legacy', ['product', 'all_products', 'legacy'], true)) {
                $itemDiscountOptions[$id] = (string) $rule['name'];
            }
            if (in_array($rule['apply_to'] ?? 'legacy', ['order_subtotal', 'legacy'], true)) {
                $orderDiscountOptions[$id] = (string) $rule['name'];
            }
        }

        $billing = $order['billing_address'] ?? [];
        $shipping = $order['shipping_address'] ?? [];
        $definitions = [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'update_order'],
            ['name' => 'email', 'type' => 'email', 'label' => 'Customer email', 'required' => true, 'value' => $order['email'] ?? ''],
            ['name' => 'customer_first_name', 'type' => 'text', 'label' => 'Customer first name', 'required' => true, 'value' => $billing['first_name'] ?? ''],
            ['name' => 'customer_last_name', 'type' => 'text', 'label' => 'Customer last name', 'required' => true, 'value' => $billing['last_name'] ?? ''],
            ['name' => 'customer_phone', 'type' => 'tel', 'label' => 'Customer phone', 'value' => $billing['phone'] ?? ''],
        ];
        foreach (['billing' => $billing, 'shipping' => $shipping] as $prefix => $address) {
            foreach ([
                'first_name' => 'First name',
                'last_name' => 'Last name',
                'company' => 'Company',
                'line1' => 'Address line 1',
                'line2' => 'Address line 2',
                'city' => 'City',
                'state' => 'Region / state',
                'postal_code' => 'Postal code',
                'country_code' => 'Country code',
                'phone' => 'Phone',
            ] as $key => $label) {
                $definitions[] = [
                    'name' => $prefix . '_' . $key,
                    'type' => 'text',
                    'label' => ucfirst($prefix) . ' ' . $label,
                    'required' => in_array($key, ['first_name', 'last_name', 'line1', 'city', 'postal_code', 'country_code'], true),
                    'value' => $address[$key] ?? '',
                ];
            }
        }
        $definitions[] = [
            'name' => 'shipping_method',
            'type' => 'select',
            'label' => 'Shipping method',
            'required' => true,
            'value' => $order['shipping_method_id'] ?? '',
            'options' => $shippingOptions,
        ];
        $existingItems = array_values($order['items'] ?? []);
        foreach (array_slice(array_pad($existingItems, count($existingItems) + 3, []), 0, count($existingItems) + 3) as $index => $item) {
            $itemClass = 'order-edit-line-' . $index;
            $definitions[] = [
                'name' => 'items[' . $index . '][variant_id]',
                'type' => 'select',
                'label' => 'Item ' . ($index + 1) . ' variant',
                'value' => isset($item['variant_id']) ? (string) $item['variant_id'] : '',
                'options' => $variantOptions,
                'wrapper_class' => $itemClass,
            ];
            $definitions[] = [
                'name' => 'items[' . $index . '][quantity]',
                'type' => 'number',
                'label' => 'Item ' . ($index + 1) . ' quantity',
                'value' => isset($item['quantity']) ? (string) $item['quantity'] : '',
                'attributes' => ['min' => '1'],
                'wrapper_class' => $itemClass,
            ];
            $itemAdjustments = json_decode((string) ($item['item_adjustments'] ?? '[]'), true);
            $itemDiscountId = '';
            $itemTaxIds = [];
            foreach (is_array($itemAdjustments) ? $itemAdjustments : [] as $adjustment) {
                if (($adjustment['kind'] ?? '') === 'discount') {
                    $itemDiscountId = (string) ($adjustment['id'] ?? '');
                } elseif (($adjustment['kind'] ?? '') === 'tax') {
                    $itemTaxIds[] = (string) ($adjustment['id'] ?? '');
                }
            }
            $definitions[] = [
                'name' => 'items[' . $index . '][discount_id]',
                'type' => 'select',
                'label' => 'Item ' . ($index + 1) . ' discount',
                'value' => $itemDiscountId,
                'options' => $itemDiscountOptions,
                'wrapper_class' => $itemClass,
            ];
            $definitions[] = [
                'name' => 'items[' . $index . '][tax_ids][]',
                'type' => 'select',
                'label' => 'Item ' . ($index + 1) . ' taxes',
                'value' => $itemTaxIds,
                'options' => $itemTaxOptions,
                'multiple' => true,
                'wrapper_class' => $itemClass,
            ];
        }
        $orderDiscountId = '';
        $orderTaxIds = [];
        foreach ($order['adjustments'] ?? [] as $adjustment) {
            if (($adjustment['kind'] ?? '') === 'discount') {
                $orderDiscountId = (string) ($adjustment['id'] ?? '');
            } elseif (($adjustment['kind'] ?? '') === 'tax') {
                $orderTaxIds[] = (string) ($adjustment['id'] ?? '');
            }
        }
        $definitions[] = [
            'name' => 'order_discount_id',
            'type' => 'select',
            'label' => 'Order discount',
            'value' => $orderDiscountId,
            'options' => $orderDiscountOptions,
        ];
        $definitions[] = [
            'name' => 'order_tax_ids[]',
            'type' => 'select',
            'label' => 'Order taxes',
            'value' => $orderTaxIds,
            'options' => $orderTaxOptions,
            'multiple' => true,
        ];
        $definitions[] = ['name' => 'payment_method', 'type' => 'hidden', 'value' => 'payment-link'];
        $definitions[] = ['name' => 'confirm_payment', 'type' => 'hidden', 'value' => 'yes'];

        return $this->renderer->render(
            $request,
            '/stockflow/orders/edit?id=' . (int) $order['id'],
            $definitions,
            'post',
            'Save order changes'
        );
    }

    public function paymentReference(Request $request, int $paymentId, string $reference = '', string $action = 'edit_payment'): string
    {
        return $this->renderer->render($request, '/stockflow/payments/edit?id=' . $paymentId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => $action],
            ['name' => 'provider_payment_id', 'type' => 'text', 'label' => 'Payment reference', 'value' => $reference],
        ], 'post', 'Save payment reference');
    }

    public function manualPayment(Request $request, int $orderId, string $reference = ''): string
    {
        return $this->renderer->render($request, '/stockflow/orders/manual-payment?id=' . $orderId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'create_manual_payment'],
            ['name' => 'provider_payment_id', 'type' => 'text', 'label' => 'Offline payment reference (optional)', 'value' => $reference],
        ], 'post', 'Add offline payment record');
    }

    public function receiveManualPayment(Request $request, int $paymentId): string
    {
        return $this->renderer->render($request, '/stockflow/payments/receive?id=' . $paymentId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'receive_payment'],
        ], 'post', 'Confirm full payment received');
    }

    public function paymentDelete(Request $request, int $paymentId): string
    {
        return $this->renderer->render($request, '/stockflow/payments/delete?id=' . $paymentId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'delete_payment'],
        ], 'post', 'Delete pending payment');
    }

    public function paymentRequestEmail(Request $request, int $orderId): string
    {
        return $this->renderer->render($request, '/stockflow/orders/payment-request?id=' . $orderId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'send_payment_request'],
        ], 'post', 'Email payment request');
    }

    public function invoiceRecipient(Request $request, int $orderId, string $customerEmail): string
    {
        return $this->renderer->render($request, '/stockflow/orders/invoice?id=' . $orderId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'send_order_invoice'],
            [
                'name' => 'recipient_email',
                'type' => 'email',
                'label' => 'Invoice recipient (leave blank to use customer email)',
                'value' => $customerEmail,
            ],
        ], 'post', 'Email invoice');
    }

    public function orderDelete(Request $request, int $orderId): string
    {
        return $this->renderer->render($request, '/stockflow/orders/delete?id=' . $orderId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'delete_order'],
        ], 'post', 'Delete pending unpaid order');
    }

    public function gatewayReconciliation(Request $request, int $paymentId, string $reference = ''): string
    {
        return $this->renderer->render($request, '/stockflow/payments/reconcile?id=' . $paymentId, [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'reconcile_payment'],
            ['name' => 'provider_payment_id', 'type' => 'text', 'label' => 'Gateway transaction ID', 'required' => true, 'value' => $reference],
            ['name' => 'confirm_gateway_capture', 'type' => 'select', 'label' => 'Gateway verification', 'required' => true, 'options' => [
                '' => 'Select confirmation',
                'yes' => 'I verified this transaction is captured/settled in the gateway',
            ]],
        ], 'post', 'Confirm gateway payment received');
    }

    public function customerPayment(Request $request, array $methods, array $selectedMethod = []): string
    {
        $options = [];
        foreach ($methods as $method) {
            $options[(string) $method['id']] = (string) $method['label'];
        }
        $definitions = [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'submit_customer_payment'],
            ['name' => 'payment_method', 'type' => 'select', 'label' => 'Payment method', 'required' => true, 'options' => $options],
        ];
        foreach ($methods as $method) {
            foreach ($method['payment_fields'] ?? [] as $field) {
                $name = (string) ($field['name'] ?? '');
                if ($name === '' || $method['id'] === 'authorize_net') {
                    continue;
                }
                $definitions[] = [
                    'name' => 'payment_data[' . $name . ']',
                    'type' => $field['type'] ?? 'text',
                    'label' => $field['label'] ?? $name,
                    'required' => in_array($name, $method['required_payment_data'] ?? [], true),
                    'value' => $selectedMethod['payment_data'][$name] ?? '',
                    'wrapper_class' => 'payment-provider-field',
                    'attributes' => ['data-payment-provider' => $method['id']],
                ];
            }
        }
        $definitions[] = ['name' => 'payment_data[opaque_data_descriptor]', 'type' => 'hidden', 'value' => ''];
        $definitions[] = ['name' => 'payment_data[opaque_data_value]', 'type' => 'hidden', 'value' => ''];
        return $this->renderer->render(
            $request,
            $request->getRequestUri(),
            $definitions,
            'post',
            'Pay securely'
        );
    }

    public function manualOrder(
        Request $request,
        array $variants,
        array $shippingMethods,
        array $paymentMethods,
        string $currency,
        array $taxRules = [],
        array $discountRules = []
    ): string {
        $this->clearLegacyManualOrderState();
        return $this->renderManualOrderWizard(
            $this->createManualOrderWizard($request, $variants, $shippingMethods, $paymentMethods, $currency, $taxRules, $discountRules),
            $variants,
            $shippingMethods,
            $paymentMethods,
            $currency,
            $taxRules,
            $discountRules
        );
    }

    public function processManualOrderWizard(
        Request $request,
        array $variants,
        array $shippingMethods,
        array $paymentMethods,
        string $currency,
        array $taxRules = [],
        array $discountRules = []
    ): array {
        $this->clearLegacyManualOrderState();
        $wizard = $this->createManualOrderWizard($request, $variants, $shippingMethods, $paymentMethods, $currency, $taxRules, $discountRules);
        $submitted = $request->request->all();
        $currentStep = (string) ($submitted['_multistep_step'] ?? '');
        if ($currentStep === '1') {
            $submitted['items'] = is_array($submitted['items'] ?? null) ? $submitted['items'] : [];
            foreach ($submitted['items'] as &$item) {
                if (is_array($item) && !isset($item['tax_ids'])) {
                    $item['tax_ids'] = [];
                }
            }
            unset($item);
        } elseif ($currentStep === '2') {
            $submitted['order_tax_ids'] = is_array($submitted['order_tax_ids'] ?? null) ? $submitted['order_tax_ids'] : [];
            $submitted['order_discount_id'] = is_string($submitted['order_discount_id'] ?? null)
                ? $submitted['order_discount_id']
                : '';
        }
        if (($submitted['_multistep_action'] ?? '') === 'back'
            && $currentStep === '5') {
            $store = new PhpSessionFormStateStore();
            $savedValues = $store->load(self::MANUAL_ORDER_WIZARD_KEY);
            unset($savedValues['payment_data'], $savedValues['_multistep_complete']);
            $store->save(self::MANUAL_ORDER_WIZARD_KEY, $savedValues);
            unset($submitted['payment_data']);
        }
        $wizard->processSubmission(self::MANUAL_ORDER_WIZARD_KEY, $submitted);

        return [
            'complete' => $wizard->isComplete(self::MANUAL_ORDER_WIZARD_KEY),
            'values' => $wizard->getValues(self::MANUAL_ORDER_WIZARD_KEY),
            'html' => $this->renderManualOrderWizard($wizard, $variants, $shippingMethods, $paymentMethods, $currency, $taxRules, $discountRules),
        ];
    }

    public function clearManualOrderWizard(): void
    {
        $store = new PhpSessionFormStateStore();
        $store->clear(self::MANUAL_ORDER_WIZARD_KEY);
        $this->clearLegacyManualOrderState();
    }

    public function hasManualOrderWizardState(): bool
    {
        return (new PhpSessionFormStateStore())->load(self::MANUAL_ORDER_WIZARD_KEY) !== [];
    }

    public function resetManualOrderWizardCompletion(): void
    {
        $store = new PhpSessionFormStateStore();
        $values = $store->load(self::MANUAL_ORDER_WIZARD_KEY);
        unset($values['_multistep_complete']);
        $values['_multistep_step'] = 6;
        $store->save(self::MANUAL_ORDER_WIZARD_KEY, $values);
    }

    public function clearManualOrderPaymentData(): void
    {
        $store = new PhpSessionFormStateStore();
        $values = $store->load(self::MANUAL_ORDER_WIZARD_KEY);
        unset($values['payment_data']);
        $store->save(self::MANUAL_ORDER_WIZARD_KEY, $values);
    }

    private function clearLegacyManualOrderState(): void
    {
        $store = new PhpSessionFormStateStore();
        $store->clear(self::LEGACY_MANUAL_ORDER_WIZARD_KEY_V2);
        $store->clear(self::LEGACY_MANUAL_ORDER_WIZARD_KEY);
    }

    private function createManualOrderWizard(
        Request $request,
        array $variants,
        array $shippingMethods,
        array $paymentMethods,
        string $currency,
        array $taxRules,
        array $discountRules
    ): MultiStepFormWrapper {
        $variantOptions = ['' => 'Choose a product variant'];
        foreach ($variants as $variant) {
            $optionLabel = $variant['product_name'] . ' — ' . $variant['sku'];
            if (!empty($variant['option_label'])) {
                $optionLabel .= ' (' . $variant['option_label'] . ')';
            }
            $optionLabel .= ' — ' . $variant['display_price'] . ' (available: '
                . ((int) $variant['quantity_on_hand'] - (int) $variant['quantity_reserved']) . ')';
            $variantOptions[(string) $variant['id']] = $optionLabel;
        }
        $shippingOptions = [];
        foreach ($shippingMethods as $method) {
            $shippingOptions[$method['id']] = $method['name'];
        }
        $itemDiscountRules = array_values(array_filter(
            $discountRules,
            static fn (array $rule): bool => in_array($rule['apply_to'] ?? 'legacy', ['product', 'all_products', 'legacy'], true)
        ));
        $orderDiscountRules = array_values(array_filter(
            $discountRules,
            static fn (array $rule): bool => in_array($rule['apply_to'] ?? 'legacy', ['order_subtotal', 'legacy'], true)
        ));
        $itemTaxRules = array_values(array_filter(
            $taxRules,
            static fn (array $rule): bool => in_array($rule['apply_to'] ?? 'legacy', ['product', 'all_products', 'legacy'], true)
        ));
        $orderTaxRules = array_values(array_filter(
            $taxRules,
            static fn (array $rule): bool => in_array($rule['apply_to'] ?? 'legacy', ['order_subtotal', 'legacy'], true)
        ));
        $discountOptions = ['' => 'No item discount'];
        foreach ($itemDiscountRules as $rule) {
            $discountOptions[(string) $rule['id']] = $rule['name'] . ' (' . $rule['amount']
                . (($rule['calculation_type'] ?? '') === 'percentage' ? '%' : ' ' . $currency) . ')';
        }
        $defaultPaymentMethod = (string) ($paymentMethods[0]['id'] ?? '');
        $this->clearUnavailableManualOrderDiscounts($discountRules);
        $savedValues = (new PhpSessionFormStateStore())->load(self::MANUAL_ORDER_WIZARD_KEY);
        $savedItems = is_array($savedValues['items'] ?? null) ? $savedValues['items'] : [];
        $itemCount = max(1, min(50, count($savedItems)));
        $wizard = new MultiStepFormWrapper(
            new PhpSessionFormStateStore(),
            new MultiStepFormStyles('numbered-stepper')
        );
        $wizard->setAttribute('id', 'stockflow-manual-order');
        $wizard->setAttribute('class', 'stockflow-manual-order');
        $wizard->setAction('/stockflow/orders/create');
        $wizard->addStep('Customer details', $this->fields($request, [
            ['name' => 'email', 'type' => 'email', 'label' => 'Customer email', 'required' => true],
            ['name' => 'customer_first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
            ['name' => 'customer_last_name', 'type' => 'text', 'label' => 'Last name', 'required' => true],
            ['name' => 'customer_phone', 'type' => 'tel', 'label' => 'Phone'],
        ]), '1');
        $itemDefinitions = [];
        for ($index = 0; $index < $itemCount; $index++) {
            $rowClass = 'order-line order-line-' . $index;
            $itemDefinitions[] = [
                'name' => 'items[' . $index . '][variant_id]',
                'type' => 'select',
                'label' => 'Product variant',
                'required' => true,
                'options' => $variantOptions,
                'wrapper_class' => $rowClass,
                'group_title' => $index === 0 ? 'Order items' : '',
            ];
            $itemDefinitions[] = [
                'name' => 'items[' . $index . '][quantity]',
                'type' => 'number',
                'label' => 'Quantity',
                'required' => true,
                'value' => '1',
                'attributes' => ['min' => '1'],
                'wrapper_class' => $rowClass,
            ];
            $itemDefinitions[] = [
                'name' => 'items[' . $index . '][discount_id]',
                'type' => 'select',
                'label' => 'Item discount',
                'options' => $discountOptions,
                'wrapper_class' => $rowClass,
            ];
            $itemDefinitions[] = [
                'name' => 'items[' . $index . '][tax_selector]',
                'type' => 'text',
                'label' => 'Item taxes',
                'wrapper_class' => $rowClass,
            ];
        }
        $wizard->addStep('Cart items', $this->fields($request, $itemDefinitions), '2');
        $overallDiscountOptions = ['' => 'No order discount'];
        foreach ($orderDiscountRules as $rule) {
            $overallDiscountOptions[(string) $rule['id']] = $rule['name'] . ' (' . $rule['amount']
                . (($rule['calculation_type'] ?? '') === 'percentage' ? '%' : ' ' . $currency) . ')';
        }
        $wizard->addStep('Order adjustments', $this->fields($request, [
            ['name' => 'order_discount_id', 'type' => 'select', 'label' => 'Order discount', 'options' => $overallDiscountOptions],
            ['name' => 'order_tax_selector', 'type' => 'text', 'label' => 'Order taxes'],
        ]), '3');
        $billingDefinitions = $this->addressFields('billing');
        $wizard->addStep('Billing', $this->fields($request, $billingDefinitions), '4');
        $shippingDefinitions = [
            ['name' => 'shipping_method', 'type' => 'select', 'label' => 'Shipping method', 'required' => true, 'options' => $shippingOptions],
            ['name' => 'same_shipping', 'type' => 'select', 'label' => 'Shipping address', 'value' => 'yes', 'options' => [
                'yes' => 'Use billing address',
                'no' => 'Enter a separate shipping address',
            ]],
        ];
        $shippingDefinitions = array_merge($shippingDefinitions, $this->addressFields('shipping'));
        $wizard->addStep('Shipping', $this->fields($request, $shippingDefinitions), '5');

        $paymentDefinitions = [
            ['name' => 'payment_method', 'type' => 'hidden', 'value' => $defaultPaymentMethod],
        ];
        foreach ($paymentMethods as $method) {
            foreach ($method['payment_fields'] ?? [] as $paymentField) {
                $fieldName = (string) ($paymentField['name'] ?? '');
                if ($fieldName === '' || $method['id'] === 'authorize_net') {
                    continue;
                }
                $paymentDefinitions[] = [
                    'name' => 'payment_data[' . $fieldName . ']',
                    'type' => $paymentField['type'] ?? 'text',
                    'label' => $paymentField['label'] ?? $fieldName,
                    'value' => $paymentField['default'] ?? null,
                    'placeholder' => $paymentField['placeholder'] ?? '',
                    'payment_provider' => $method['id'],
                    'payment_required' => !empty($paymentField['required'])
                        || in_array($fieldName, $method['required_payment_data'] ?? [], true),
                ];
            }
        }
        $wizard->addStep('Payment', $this->fields($request, $paymentDefinitions), '6');
        $reviewHtmlField = new NamedInputField('text', 'review_summary');
        $wizard->addStep('Review', array_merge($this->fields($request, [
            ['name' => 'confirm_payment', 'type' => 'hidden', 'value' => 'yes'],
        ]), [$reviewHtmlField]), '7');

        return $wizard;
    }

    private function clearUnavailableManualOrderDiscounts(array $discountRules): void
    {
        $store = new PhpSessionFormStateStore();
        $values = $store->load(self::MANUAL_ORDER_WIZARD_KEY);
        if ($values === []) {
            return;
        }

        $activeIds = [];
        foreach ($discountRules as $rule) {
            $id = (string) ($rule['id'] ?? '');
            if ($id !== '') {
                $activeIds[$id] = true;
            }
        }

        $changed = false;
        if (is_array($values['items'] ?? null)) {
            foreach ($values['items'] as &$item) {
                if (!is_array($item) || !array_key_exists('discount_id', $item)) {
                    continue;
                }
                $id = is_string($item['discount_id']) ? trim($item['discount_id']) : '';
                if ($id === '') {
                    if ($item['discount_id'] !== '') {
                        $item['discount_id'] = '';
                        $changed = true;
                    }
                    continue;
                }
                if (!isset($activeIds[$id])) {
                    $item['discount_id'] = '';
                    $changed = true;
                }
            }
            unset($item);
        }

        if (array_key_exists('order_discount_id', $values)) {
            $id = is_string($values['order_discount_id']) ? trim($values['order_discount_id']) : '';
            if ($id === '' || !isset($activeIds[$id])) {
                if ($values['order_discount_id'] !== '') {
                    $values['order_discount_id'] = '';
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $store->save(self::MANUAL_ORDER_WIZARD_KEY, $values);
        }
    }

    private function addressFields(string $prefix): array
    {
        return [
            ['name' => $prefix . '_first_name', 'type' => 'text', 'label' => ucfirst($prefix) . ' first name', 'required' => true, 'wrapper_class' => $prefix . '-address-fields', 'group_title' => ucfirst($prefix) . ' address'],
            ['name' => $prefix . '_last_name', 'type' => 'text', 'label' => ucfirst($prefix) . ' last name', 'required' => true, 'wrapper_class' => $prefix . '-address-fields'],
            ['name' => $prefix . '_company', 'type' => 'text', 'label' => ucfirst($prefix) . ' company', 'wrapper_class' => $prefix . '-address-fields'],
            ['name' => $prefix . '_line1', 'type' => 'text', 'label' => ucfirst($prefix) . ' address line 1', 'required' => true, 'wrapper_class' => $prefix . '-address-fields'],
            ['name' => $prefix . '_line2', 'type' => 'text', 'label' => ucfirst($prefix) . ' address line 2', 'wrapper_class' => $prefix . '-address-fields'],
            ['name' => $prefix . '_city', 'type' => 'text', 'label' => ucfirst($prefix) . ' city', 'required' => true, 'wrapper_class' => $prefix . '-address-fields'],
            ['name' => $prefix . '_state', 'type' => 'text', 'label' => ucfirst($prefix) . ' region / state', 'wrapper_class' => $prefix . '-address-fields'],
            ['name' => $prefix . '_postal_code', 'type' => 'text', 'label' => ucfirst($prefix) . ' postal code', 'required' => true, 'wrapper_class' => $prefix . '-address-fields'],
            ['name' => $prefix . '_country_code', 'type' => 'text', 'label' => ucfirst($prefix) . ' country code (ISO 2-letter)', 'required' => true, 'value' => 'MW', 'wrapper_class' => $prefix . '-address-fields'],
            ['name' => $prefix . '_phone', 'type' => 'tel', 'label' => ucfirst($prefix) . ' phone', 'wrapper_class' => $prefix . '-address-fields'],
        ];
    }

    private function renderManualOrderWizard(
        MultiStepFormWrapper $wizard,
        array $variants,
        array $shippingMethods,
        array $paymentMethods,
        string $currency,
        array $taxRules,
        array $discountRules
    ): string
    {
        $values = $wizard->getValues(self::MANUAL_ORDER_WIZARD_KEY);
        $itemTaxRules = array_values(array_filter(
            $taxRules,
            static fn (array $rule): bool => in_array($rule['apply_to'] ?? 'legacy', ['product', 'all_products', 'legacy'], true)
        ));
        $orderTaxRules = array_values(array_filter(
            $taxRules,
            static fn (array $rule): bool => in_array($rule['apply_to'] ?? 'legacy', ['order_subtotal', 'legacy'], true)
        ));
        $itemDiscountRules = array_values(array_filter(
            $discountRules,
            static fn (array $rule): bool => in_array($rule['apply_to'] ?? 'legacy', ['product', 'all_products', 'legacy'], true)
        ));
        $selectedPaymentMethod = (string) ($values['payment_method'] ?? $paymentMethods[0]['id'] ?? '');
        $reviewSummaryMarkup = $this->manualOrderReviewSummary(
            $values,
            $variants,
            $shippingMethods,
            $paymentMethods,
            $currency,
            $taxRules,
            $discountRules
        );
        $paymentMethodsMarkup = '<fieldset class="payment-method-options"><legend>Payment method</legend>';
        foreach ($paymentMethods as $index => $method) {
            $id = (string) $method['id'];
            $checked = $id === $selectedPaymentMethod;
            $inputId = 'payment-method-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $id);
            $paymentMethodsMarkup .= '<label class="payment-method-option" for="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="radio" id="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '" name="payment_method" value="'
                . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . ($checked ? ' checked' : '') . ($index === 0 ? ' required' : '') . '>'
                . '<span><strong>' . htmlspecialchars((string) $method['label'], ENT_QUOTES, 'UTF-8') . '</strong>'
                . '<small>' . htmlspecialchars(implode(', ', $method['currencies'] ?? []), ENT_QUOTES, 'UTF-8') . '</small></span></label>';
            if ($id === 'authorize_net') {
                $clientAuth = is_array($method['authorize_client'] ?? null) ? $method['authorize_client'] : [];
                $paymentData = is_array($values['payment_data'] ?? null) ? $values['payment_data'] : [];
                $paymentMethodsMarkup .= '<div class="authorize-card-fields" data-payment-provider="authorize_net" data-api-login-id="'
                    . htmlspecialchars((string) ($clientAuth['api_login_id'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . '" data-client-key="' . htmlspecialchars((string) ($clientAuth['client_key'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . '" data-environment="' . htmlspecialchars((string) ($clientAuth['environment'] ?? 'sandbox'), ENT_QUOTES, 'UTF-8') . '" hidden>'
                    . '<p class="sub">Card details are sent directly to Authorize.Net for secure tokenization.</p>'
                    . '<div class="authorize-card-grid">'
                    . '<label>Card number<input type="text" inputmode="numeric" autocomplete="cc-number" data-card-number></label>'
                    . '<label>Expiration month<input type="text" inputmode="numeric" autocomplete="cc-exp-month" placeholder="MM" maxlength="2" data-card-month></label>'
                    . '<label>Expiration year<input type="text" inputmode="numeric" autocomplete="cc-exp-year" placeholder="YYYY" maxlength="4" data-card-year></label>'
                    . '<label>Security code<input type="password" inputmode="numeric" autocomplete="cc-csc" maxlength="4" data-card-code></label>'
                    . '</div><p class="authorize-card-error" role="alert" hidden></p></div>'
                    . '<input type="hidden" name="payment_data[opaque_data_descriptor]" value="'
                    . htmlspecialchars((string) ($paymentData['opaque_data_descriptor'] ?? ''), ENT_QUOTES, 'UTF-8') . '">'
                    . '<input type="hidden" name="payment_data[opaque_data_value]" value="'
                    . htmlspecialchars((string) ($paymentData['opaque_data_value'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
            }
        }
        $paymentMethodsMarkup .= '</fieldset>';

        return $wizard->render(
            self::MANUAL_ORDER_WIZARD_KEY,
            $wizard->getCurrentStep(self::MANUAL_ORDER_WIZARD_KEY),
            function ($field) use (
                $values,
                $paymentMethodsMarkup,
                $reviewSummaryMarkup,
                $itemTaxRules,
                $orderTaxRules,
                $variants,
                $itemDiscountRules
            ): string {
                if ($field->getName() === 'payment_method') {
                    return $paymentMethodsMarkup;
                }
                if ($field->getName() === 'review_summary') {
                    return $reviewSummaryMarkup;
                }
                if (preg_match('/^items\[(\d+)\]\[tax_selector\]$/', $field->getName(), $matches)) {
                    $index = (int) $matches[1];
                    $selected = $values['items'][$index]['tax_ids'] ?? [];
                    $variantProducts = [];
                    foreach ($variants as $variant) {
                        $variantProducts[(string) $variant['id']] = (int) $variant['product_id'];
                    }
                    $discountRuleMetadata = [];
                    foreach ($itemDiscountRules as $rule) {
                        $discountRuleMetadata[(string) $rule['id']] = [
                            'apply_to' => $rule['apply_to'] ?? 'legacy',
                            'product_id' => $rule['product_id'] ?? null,
                        ];
                    }
                    $metadata = ' data-variant-products="' . htmlspecialchars(json_encode($variantProducts, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8')
                        . '" data-discount-rules="' . htmlspecialchars(json_encode($discountRuleMetadata, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') . '"';
                    return '<div class="form-field order-line order-line-' . $index . '"' . $metadata . '>'
                        . $this->ruleCheckboxes($itemTaxRules, 'items[' . $index . '][tax_ids][]', $selected, 'item-tax-' . $index, true)
                        . '</div>';
                }
                if ($field->getName() === 'order_tax_selector') {
                    return '<div class="form-field">'
                        . $this->ruleCheckboxes($orderTaxRules, 'order_tax_ids[]', $values['order_tax_ids'] ?? [], 'order-tax', false)
                        . '</div>';
                }
                [$hasNestedValue, $nestedValue] = self::nestedFormValue($values, $field->getName());
                if ($hasNestedValue) {
                    $field->setValue($nestedValue);
                } elseif ($field->getName() === 'billing_first_name' && !empty($values['customer_first_name'])) {
                    $field->setValue($values['customer_first_name']);
                } elseif ($field->getName() === 'billing_last_name' && !empty($values['customer_last_name'])) {
                    $field->setValue($values['customer_last_name']);
                } elseif ($field->getName() === 'billing_phone' && !empty($values['customer_phone'])) {
                    $field->setValue($values['customer_phone']);
                }
                if (str_starts_with($field->getName(), 'shipping_')
                    && ($values['same_shipping'] ?? 'yes') === 'yes') {
                    $field->setRequired(false);
                }
                $markup = Helper::renderField($field, $values);
                if ($field->getType() === 'hidden') {
                    return $markup;
                }
                $provider = (string) $field->getAttribute('data-payment-provider');
                $required = $field->getAttribute('data-payment-required') === true ? 'true' : 'false';
                $wrapperClass = 'form-field';
                if (preg_match('/^items\[(\d+)\]/', $field->getName(), $matches)) {
                    $wrapperClass .= ' order-line order-line-' . $matches[1];
                } elseif (str_starts_with($field->getName(), 'billing_')) {
                    $wrapperClass .= ' billing-address-fields';
                } elseif (str_starts_with($field->getName(), 'shipping_')) {
                    $wrapperClass .= ' shipping-address-fields';
                }
                if ($provider !== '') {
                    $wrapperClass .= ' payment-provider-field';
                }
                $hidden = str_starts_with($field->getName(), 'shipping_')
                    && ($values['same_shipping'] ?? 'yes') === 'yes'
                    ? ' hidden'
                    : '';
                $attributes = $provider === ''
                    ? ''
                    : ' data-payment-provider="' . htmlspecialchars($provider, ENT_QUOTES, 'UTF-8') . '" data-payment-required="' . $required . '"';
                return '<div class="' . $wrapperClass . '"' . $hidden . $attributes . '>' . $markup . '</div>';
            }
        );
    }

    private function ruleCheckboxes(array $rules, string $name, mixed $selected, string $class, bool $isItem): string
    {
        $selected = is_array($selected) ? array_map('strval', $selected) : [];
        $markup = '<fieldset class="rule-checkboxes"><legend>' . ($isItem ? 'Apply taxes to this item' : 'Apply taxes to the order') . '</legend>';
        foreach ($rules as $rule) {
            $id = (string) ($rule['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $inputId = $class . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $id);
            $scope = (string) ($rule['apply_to'] ?? 'legacy');
            $productId = (string) ($rule['product_id'] ?? '');
            $markup .= '<label for="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8')
                . '" data-rule-scope="' . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8')
                . '" data-rule-product="' . htmlspecialchars($productId, ENT_QUOTES, 'UTF-8') . '"><input type="checkbox" class="' . $class
                . '" id="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                . '" value="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . (in_array($id, $selected, true) ? ' checked' : '') . '>'
                . '<span>' . htmlspecialchars((string) $rule['name'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars((string) $rule['amount'], ENT_QUOTES, 'UTF-8')
                . (($rule['calculation_type'] ?? '') === 'percentage' ? '%' : '') . ')</span></label>';
        }
        if ($rules === []) {
            $markup .= '<p>No active tax rules are configured.</p>';
        }
        return $markup . '</fieldset>';
    }

    private function manualOrderReviewSummary(
        array $values,
        array $variants,
        array $shippingMethods,
        array $paymentMethods,
        string $currency,
        array $taxRules,
        array $discountRules
    ): string {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $variantById = [];
        $subtotal = new Price(0.0, $currency);
        $itemDiscountTotal = new Price(0.0, $currency);
        $itemTaxTotal = new Price(0.0, $currency);
        foreach ($variants as $variant) {
            $variantById[(string) $variant['id']] = $variant;
        }
        $itemRows = '';
        foreach (($values['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $variant = $variantById[(string) ($item['variant_id'] ?? '')] ?? null;
            if ($variant === null) {
                continue;
            }
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1) {
                continue;
            }
            $label = $variant['product_name'] . ' — ' . $variant['sku'];
            if (!empty($variant['option_label'])) {
                $label .= ' (' . $variant['option_label'] . ')';
            }
            $lineTotal = (new Price((float) $variant['price'], $currency))->multiply($quantity);
            $subtotal->add($lineTotal);
            $discountIds = !empty($item['discount_id']) ? [(string) $item['discount_id']] : [];
            $lineDiscountRules = $this->rulesForProduct($discountRules, (int) $variant['product_id']);
            $discountedTotal = $this->previewAdjustments(
                new Price($lineTotal->getAmount(), $currency),
                $discountIds,
                $lineDiscountRules,
                'discount'
            );
            $lineDiscount = (new Price($lineTotal->getAmount(), $currency))->subtract($discountedTotal);
            $itemDiscountTotal->add($lineDiscount);
            $taxIds = is_array($item['tax_ids'] ?? null) ? array_map('strval', $item['tax_ids']) : [];
            $lineTaxRules = $this->rulesForProduct($taxRules, (int) $variant['product_id']);
            $taxedTotal = $this->previewAdjustments($discountedTotal, $taxIds, $lineTaxRules, 'tax');
            $lineTax = (new Price($taxedTotal->getAmount(), $currency))->subtract($discountedTotal);
            $itemTaxTotal->add($lineTax);
            $adjustmentNames = array_merge(
                $this->selectedRuleNames($discountIds, $lineDiscountRules),
                $this->selectedRuleNames($taxIds, $lineTaxRules)
            );
            $itemRows .= '<li><span>' . $escape($label) . ' × ' . $escape($quantity) . '<small>'
                . $escape($variant['display_price']) . ' each'
                . ($adjustmentNames === [] ? '' : '<br>' . $escape(implode(', ', $adjustmentNames)))
                . '</small></span><strong>' . $escape($taxedTotal->format()) . '</strong></li>';
        }
        if ($itemRows === '') {
            $itemRows = '<li>No order items selected.</li>';
        }
        $shippingMethodName = 'Not selected';
        foreach ($shippingMethods as $method) {
            if ((string) ($method['id'] ?? '') === (string) ($values['shipping_method'] ?? '')) {
                $shippingMethodName = (string) $method['name'];
                break;
            }
        }
        $paymentMethodName = 'Not selected';
        foreach ($paymentMethods as $method) {
            if ((string) ($method['id'] ?? '') === (string) ($values['payment_method'] ?? '')) {
                $paymentMethodName = (string) $method['label'];
                break;
            }
        }
        $shippingPrefix = ($values['same_shipping'] ?? 'yes') === 'yes' ? 'billing' : 'shipping';
        $addressMarkup = static function (array $data, string $prefix) use ($escape): string {
            $parts = array_filter([
                trim((string) ($data[$prefix . '_first_name'] ?? '') . ' ' . (string) ($data[$prefix . '_last_name'] ?? '')),
                $data[$prefix . '_line1'] ?? '',
                $data[$prefix . '_line2'] ?? '',
                trim((string) ($data[$prefix . '_city'] ?? '') . ', ' . (string) ($data[$prefix . '_state'] ?? '') . ' ' . (string) ($data[$prefix . '_postal_code'] ?? '')),
                $data[$prefix . '_country_code'] ?? '',
            ], static fn (mixed $part): bool => trim((string) $part) !== '');
            return implode('<br>', array_map($escape, $parts));
        };
        $billingMarkup = $addressMarkup($values, 'billing');
        $deliveryMarkup = $addressMarkup($values, $shippingPrefix);
        $overallDiscountIds = !empty($values['order_discount_id']) ? [(string) $values['order_discount_id']] : [];
        $orderDiscountRules = $this->rulesForScope($discountRules, 'order_subtotal');
        $overallDiscountBase = (new Price($subtotal->getAmount(), $currency))->subtract($itemDiscountTotal);
        $overallDiscounted = $this->previewAdjustments(
            $overallDiscountBase,
            $overallDiscountIds,
            $orderDiscountRules,
            'discount'
        );
        $overallDiscount = $overallDiscountBase->subtract($overallDiscounted);
        $discountTotal = (new Price($itemDiscountTotal->getAmount(), $currency))->add($overallDiscount);
        $overallTaxIds = is_array($values['order_tax_ids'] ?? null)
            ? array_map('strval', $values['order_tax_ids'])
            : [];
        $orderTaxRules = $this->rulesForScope($taxRules, 'order_subtotal');
        $overallTaxBase = (new Price($subtotal->getAmount(), $currency))
            ->subtract($discountTotal);
        $overallTaxed = $this->previewAdjustments($overallTaxBase, $overallTaxIds, $orderTaxRules, 'tax');
        $overallTax = $overallTaxed->subtract($overallTaxBase);
        $taxTotal = (new Price($itemTaxTotal->getAmount(), $currency))->add($overallTax);
        $estimatedTotal = (new Price($subtotal->getAmount(), $currency))
            ->subtract($discountTotal)
            ->add($taxTotal);
        $discountNames = $this->selectedRuleNames($overallDiscountIds, $orderDiscountRules);
        $taxNames = $this->selectedRuleNames($overallTaxIds, $orderTaxRules);

        return '<div class="order-review">'
            . '<section><h3>Customer</h3><p>' . $escape(trim((string) ($values['customer_first_name'] ?? '') . ' ' . (string) ($values['customer_last_name'] ?? '')))
            . '<br>' . $escape($values['email'] ?? '') . '<br>' . $escape($values['customer_phone'] ?? '') . '</p></section>'
            . '<section><h3>Items</h3><ul class="order-review-items">' . $itemRows . '</ul><p class="order-review-subtotal">Merchandise subtotal: <strong>' . $escape($subtotal->format()) . '</strong></p></section>'
            . '<section><h3>Billing address</h3><p>' . $billingMarkup . '</p></section>'
            . '<section><h3>Shipping</h3><p>' . $escape($shippingMethodName) . '<br>' . $deliveryMarkup . '</p></section>'
            . '<section><h3>Payment</h3><p>' . $escape($paymentMethodName) . '</p></section>'
            . '<section><h3>Adjustments</h3><p>Discount: ' . $escape($discountTotal->format())
            . ($discountNames === [] ? '' : ' (' . $escape(implode(', ', $discountNames)) . ')')
            . '<br>Tax: ' . $escape($taxTotal->format())
            . ($taxNames === [] ? '' : ' (' . $escape(implode(', ', $taxNames)) . ')')
            . '<br>Estimated total before shipping: <strong>' . $escape($estimatedTotal->format()) . '</strong>'
            . '</p></section>'
            . '</div>';
    }

    private function previewAdjustments(Price $base, array $ids, array $rules, string $kind): Price
    {
        $adjusted = new Price($base->getAmount(), $base->getCurrency()->getCode());
        $rulesById = [];
        foreach ($rules as $rule) {
            $rulesById[(string) ($rule['id'] ?? '')] = $rule;
        }
        $adjustments = new Adjustments();
        foreach ($ids as $id) {
            $rule = $rulesById[$id] ?? null;
            if ($rule === null) {
                continue;
            }
            $amount = (float) $rule['amount'];
            $adjustments->add(new Adjustment(
                (string) $rule['name'],
                (string) $rule['description'],
                $kind === 'discount' ? -$amount : $amount,
                (string) $rule['calculation_type']
            ));
        }
        $adjustments->applyTo($adjusted);
        return $adjusted;
    }

    private function selectedRuleNames(array $ids, array $rules): array
    {
        $rulesById = [];
        foreach ($rules as $rule) {
            $rulesById[(string) ($rule['id'] ?? '')] = (string) ($rule['name'] ?? '');
        }
        return array_values(array_filter(array_map(
            static fn (string $id): string => $rulesById[$id] ?? '',
            $ids
        )));
    }

    private function rulesForScope(array $rules, string $scope): array
    {
        return array_values(array_filter(
            $rules,
            static fn (array $rule): bool => in_array($rule['apply_to'] ?? 'legacy', [$scope, 'legacy'], true)
        ));
    }

    private function rulesForProduct(array $rules, int $productId): array
    {
        return array_values(array_filter(
            $rules,
            static fn (array $rule): bool => ($rule['apply_to'] ?? 'legacy') === 'legacy'
                || ($rule['apply_to'] ?? '') === 'all_products'
                || (($rule['apply_to'] ?? '') === 'product' && (int) ($rule['product_id'] ?? 0) === $productId)
        ));
    }

    private static function nestedFormValue(array $values, string $fieldName): array
    {
        $openingBracket = strpos($fieldName, '[');
        if ($openingBracket === false || !str_ends_with($fieldName, ']')) {
            return [false, null];
        }
        $baseName = substr($fieldName, 0, $openingBracket);
        $nestedName = substr($fieldName, $openingBracket);
        preg_match_all('/\[([^\]]+)\]/', $nestedName, $pathMatches);
        $path = array_merge([$baseName], $pathMatches[1]);
        $value = $values;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return [false, null];
            }
            $value = $value[$key];
        }
        return [true, $value];
    }

    public function purchaseOrderForm(
        Request $request,
        array $suppliers,
        array $variants,
        string $currency
    ): string {
        $supplierOptions = [];
        foreach ($suppliers as $supplier) {
            $supplierOptions[$supplier['id']] = $supplier['name'] . ' (' . $supplier['code'] . ')';
        }
        $variantOptions = [];
        foreach ($variants as $variant) {
            $variantOptions[$variant['id']] = $variant['product_name'] . ' — ' . $variant['sku'];
        }

        return $this->renderer->render($request, '/stockflow/purchasing/create', [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'create_purchase_order'],
            ['name' => 'supplier_id', 'type' => 'select', 'label' => 'Supplier', 'required' => true, 'options' => $supplierOptions],
            ['name' => 'variant_id', 'type' => 'select', 'label' => 'Product variant', 'required' => true, 'options' => $variantOptions],
            ['name' => 'quantity', 'type' => 'number', 'label' => 'Quantity ordered', 'required' => true, 'value' => '1'],
            ['name' => 'unit_cost', 'type' => 'text', 'label' => 'Unit cost (' . $currency . ')', 'required' => true, 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'expected_at', 'type' => 'date', 'label' => 'Expected date'],
            ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes'],
        ], 'post', 'Create purchase order');
    }

    public function purchaseOrderEdit(
        Request $request,
        array $purchaseOrder,
        array $suppliers,
        array $variants,
        string $currency
    ): string {
        $supplierOptions = [];
        foreach ($suppliers as $supplier) {
            $supplierOptions[$supplier['id']] = $supplier['name'] . ' (' . $supplier['code'] . ')';
        }
        $variantOptions = [];
        foreach ($variants as $variant) {
            $variantOptions[$variant['id']] = $variant['product_name'] . ' — ' . $variant['sku'];
        }
        $item = $purchaseOrder['items'][0] ?? [];
        $expectedAt = $purchaseOrder['expected_at'] ?? '';

        return $this->renderer->render($request, '/stockflow/purchasing/edit?id=' . (int) $purchaseOrder['id'], [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'update_purchase_order'],
            ['name' => 'supplier_id', 'type' => 'select', 'label' => 'Supplier', 'required' => true, 'value' => (string) $purchaseOrder['supplier_id'], 'options' => $supplierOptions],
            ['name' => 'variant_id', 'type' => 'select', 'label' => 'Product variant', 'required' => true, 'value' => (string) ($item['variant_id'] ?? ''), 'options' => $variantOptions],
            ['name' => 'quantity', 'type' => 'number', 'label' => 'Quantity ordered', 'required' => true, 'value' => (string) ($item['quantity_ordered'] ?? 1), 'attributes' => ['min' => '1']],
            ['name' => 'unit_cost', 'type' => 'text', 'label' => 'Unit cost (' . $currency . ')', 'required' => true, 'value' => (string) ($item['unit_cost'] ?? ''), 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'expected_at', 'type' => 'date', 'label' => 'Expected date', 'value' => $expectedAt === '' ? '' : substr($expectedAt, 0, 10)],
            ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes', 'value' => $purchaseOrder['notes']],
        ], 'post', 'Save purchase order');
    }

    public function supplierForm(Request $request): string
    {
        return $this->renderer->render($request, '/stockflow/suppliers/create', [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'create_supplier'],
            ['name' => 'code', 'type' => 'text', 'label' => 'Supplier code', 'required' => true],
            ['name' => 'name', 'type' => 'text', 'label' => 'Supplier name', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
            ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
        ], 'post', 'Add supplier');
    }

    public function supplierEdit(Request $request, array $supplier): string
    {
        return $this->renderer->render($request, '/stockflow/suppliers/edit?id=' . (int) $supplier['id'], [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'update_supplier'],
            ['name' => 'code', 'type' => 'text', 'label' => 'Supplier code', 'required' => true, 'value' => $supplier['code']],
            ['name' => 'name', 'type' => 'text', 'label' => 'Supplier name', 'required' => true, 'value' => $supplier['name']],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => $supplier['email']],
            ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone', 'value' => $supplier['phone']],
            ['name' => 'website', 'type' => 'url', 'label' => 'Website', 'value' => $supplier['website']],
            ['name' => 'payment_terms', 'type' => 'text', 'label' => 'Payment terms', 'value' => $supplier['payment_terms']],
            ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'value' => $supplier['status'], 'options' => [
                'active' => 'Active',
                'inactive' => 'Inactive',
            ]],
        ], 'post', 'Save supplier');
    }

    public function receivePurchaseOrder(Request $request, array $purchaseOrder): string
    {
        return $this->renderer->render($request, '/stockflow/purchasing/receive?id=' . (int) $purchaseOrder['id'], [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'receive_purchase_order'],
            ['name' => 'purchase_order_id', 'type' => 'hidden', 'value' => $purchaseOrder['id']],
            ['name' => 'quantity_received', 'type' => 'number', 'label' => 'Quantity received', 'required' => true, 'value' => (string) $purchaseOrder['receivable_quantity'], 'attributes' => ['min' => '1', 'max' => (string) $purchaseOrder['receivable_quantity']]],
        ], 'post', 'Receive stock');
    }

    public function customer(Request $request): string
    {
        return $this->renderer->render($request, '/stockflow/customers/create', [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'create_customer'],
            ['name' => 'first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
            ['name' => 'last_name', 'type' => 'text', 'label' => 'Last name', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
            ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
            ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'options' => [
                'active' => 'Active',
                'guest' => 'Guest',
                'blocked' => 'Blocked',
            ]],
            ['name' => 'accepts_marketing', 'type' => 'select', 'label' => 'Marketing consent', 'options' => [
                'no' => 'Not subscribed',
                'yes' => 'Subscribed',
            ]],
        ], 'post', 'Add customer');
    }

    public function settings(Request $request, array $settings): string
    {
        return $this->renderer->render($request, '/stockflow/settings', [
            ['name' => 'store_name', 'type' => 'text', 'label' => 'Store name', 'required' => true, 'value' => $settings['store_name']],
            ['name' => 'currency', 'type' => 'select', 'label' => 'Default currency', 'value' => $settings['currency'], 'options' => [
                'USD' => 'USD — US Dollar',
                'EUR' => 'EUR — Euro',
                'GBP' => 'GBP — Pound sterling',
                'MWK' => 'MWK — Malawian kwacha',
                'ZAR' => 'ZAR — South African rand',
            ]],
            ['name' => 'timezone', 'type' => 'select', 'label' => 'Timezone', 'value' => $settings['timezone'], 'options' => [
                'UTC' => 'UTC',
                'Africa/Blantyre' => 'Africa/Blantyre',
                'Africa/Johannesburg' => 'Africa/Johannesburg',
                'Europe/London' => 'Europe/London',
                'America/New_York' => 'America/New_York',
            ]],
            ['name' => 'low_stock_alerts', 'type' => 'select', 'label' => 'Low stock alerts', 'value' => $settings['low_stock_alerts'], 'options' => ['1' => 'Enabled', '0' => 'Disabled']],
            ['name' => 'purchase_order_updates', 'type' => 'select', 'label' => 'Purchase order updates', 'value' => $settings['purchase_order_updates'], 'options' => ['1' => 'Enabled', '0' => 'Disabled']],
            ['name' => 'daily_digest', 'type' => 'select', 'label' => 'Daily digest', 'value' => $settings['daily_digest'], 'options' => ['1' => 'Enabled', '0' => 'Disabled']],
        ]);
    }

    public function adjustmentRule(
        Request $request,
        string $kind,
        array $rule,
        array $products,
        bool $new = false
    ): string
    {
        $kindLabel = $kind === 'taxes' ? 'tax' : 'discount';
        $action = $new
            ? '/stockflow/' . $kind . '/create'
            : '/stockflow/' . $kind . '/edit?id=' . rawurlencode((string) $rule['id']);
        $productOptions = ['' => 'Select a product'];
        foreach ($products as $product) {
            $productOptions[(string) $product['id']] = (string) $product['name'];
        }
        $definitions = [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'save_adjustment_rule'],
            ['name' => 'id', 'type' => 'hidden', 'value' => $rule['id'] ?? ''],
            ['name' => 'name', 'type' => 'text', 'label' => ucfirst($kindLabel) . ' name', 'required' => true, 'value' => $rule['name'] ?? ''],
            ['name' => 'description', 'type' => 'text', 'label' => 'Description', 'required' => true, 'value' => $rule['description'] ?? ''],
            ['name' => 'calculation_type', 'type' => 'select', 'label' => 'Calculation', 'required' => true, 'value' => $rule['calculation_type'] ?? 'percentage', 'options' => [
                'percentage' => 'Percentage of applicable subtotal',
                'fixed' => 'Fixed amount',
            ]],
            ['name' => 'amount', 'type' => 'text', 'label' => 'Rate / amount', 'required' => true, 'value' => (string) ($rule['amount'] ?? ''), 'attributes' => ['inputmode' => 'decimal', 'min' => '0']],
            ['name' => 'apply_to', 'type' => 'select', 'label' => 'Apply this ' . $kindLabel . ' to', 'required' => true, 'value' => ($rule['apply_to'] ?? '') === 'legacy' ? 'all_products' : ($rule['apply_to'] ?? 'all_products'), 'options' => [
                'product' => 'One selected product',
                'all_products' => 'All products (each item)',
                'order_subtotal' => 'Order subtotal',
            ]],
            ['name' => 'product_id', 'type' => 'select', 'label' => 'Product', 'value' => $rule['product_id'] ?? '', 'options' => $productOptions],
            ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'required' => true, 'value' => $rule['status'] ?? 'active', 'options' => [
                'active' => 'Active',
                'inactive' => 'Inactive',
            ]],
        ];
        if ($kind === 'discounts') {
            $definitions = array_merge(array_slice($definitions, 0, 7), [
                ['name' => 'starts_at', 'type' => 'date', 'label' => 'Valid from (optional)', 'value' => $rule['starts_at'] ?? ''],
                ['name' => 'ends_at', 'type' => 'date', 'label' => 'Valid until (optional)', 'value' => $rule['ends_at'] ?? ''],
                ['name' => 'minimum_order_total', 'type' => 'text', 'label' => 'Minimum order subtotal (optional)', 'value' => $rule['minimum_order_total'] ?? '', 'attributes' => ['inputmode' => 'decimal', 'min' => '0']],
                ['name' => 'maximum_order_total', 'type' => 'text', 'label' => 'Maximum order subtotal (optional)', 'value' => $rule['maximum_order_total'] ?? '', 'attributes' => ['inputmode' => 'decimal', 'min' => '0']],
                ['name' => 'minimum_total_items', 'type' => 'number', 'label' => 'Minimum total quantity (optional)', 'value' => $rule['minimum_total_items'] ?? '', 'attributes' => ['min' => '0']],
                ['name' => 'maximum_total_items', 'type' => 'number', 'label' => 'Maximum total quantity (optional)', 'value' => $rule['maximum_total_items'] ?? '', 'attributes' => ['min' => '0']],
                ['name' => 'minimum_distinct_products', 'type' => 'number', 'label' => 'Minimum distinct products (optional)', 'value' => $rule['minimum_distinct_products'] ?? '', 'attributes' => ['min' => '0']],
                ['name' => 'maximum_distinct_products', 'type' => 'number', 'label' => 'Maximum distinct products (optional)', 'value' => $rule['maximum_distinct_products'] ?? '', 'attributes' => ['min' => '0']],
            ], array_slice($definitions, 7));
        }
        return $this->renderer->render(
            $request,
            $action,
            $definitions,
            'post',
            $new ? 'Create ' . $kindLabel : 'Save ' . $kindLabel
        );
    }

    public function shippingMethod(Request $request, array $method = [], bool $new = false): string
    {
        $id = (string) ($method['id'] ?? '');
        $action = $new ? '/stockflow/shipping/create' : '/stockflow/shipping/edit?id=' . rawurlencode($id);
        $definitions = [
            ['name' => '_action', 'type' => 'hidden', 'value' => $new ? 'create_shipping_method' : 'update_shipping_method'],
            ['name' => 'name', 'type' => 'text', 'label' => 'Method name', 'required' => true, 'value' => $method['name'] ?? ''],
            ['name' => 'type', 'type' => 'select', 'label' => 'Shipping calculation type', 'value' => $method['type'] ?? 'flat_rate', 'options' => [
                'flat_rate' => 'Flat rate',
                'price_based' => 'Price-based / order total',
                'weight_based' => 'Weight-based',
            ]],
            ['name' => 'flat_rate', 'type' => 'text', 'label' => 'Flat rate / base fee', 'required' => true, 'value' => $method['flat_rate'] ?? '0.00', 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'free_threshold', 'type' => 'text', 'label' => 'Free shipping above subtotal (optional)', 'value' => $method['free_threshold'] ?? '', 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'minimum_order_total', 'type' => 'text', 'label' => 'Minimum order total (optional)', 'value' => $method['minimum_order_total'] ?? '', 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'maximum_order_total', 'type' => 'text', 'label' => 'Maximum order total (optional)', 'value' => $method['maximum_order_total'] ?? '', 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'rate_per_kg', 'type' => 'text', 'label' => 'Additional charge per kilogram', 'value' => $method['rate_per_kg'] ?? '', 'attributes' => ['inputmode' => 'decimal']],
            ['name' => 'minimum_weight_grams', 'type' => 'number', 'label' => 'Minimum shipment weight (grams, optional)', 'value' => $method['minimum_weight_grams'] ?? '', 'attributes' => ['min' => '0']],
            ['name' => 'maximum_weight_grams', 'type' => 'number', 'label' => 'Maximum shipment weight (grams, optional)', 'value' => $method['maximum_weight_grams'] ?? '', 'attributes' => ['min' => '0']],
            ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'value' => $method['status'] ?? 'active', 'options' => [
                'active' => 'Enabled',
                'inactive' => 'Disabled',
            ]],
        ];
        return $this->renderer->render($request, $action, $definitions, 'post', $new ? 'Create shipping method' : 'Save shipping method');
    }

    public function paymentMethod(Request $request, array $method): string
    {
        $definitions = [
            ['name' => '_action', 'type' => 'hidden', 'value' => 'save_payment_method'],
            ['name' => 'enabled', 'type' => 'select', 'label' => 'Availability', 'value' => $method['enabled'] ? 'enabled' : 'disabled', 'options' => [
                'disabled' => 'Disabled',
                'enabled' => 'Enabled',
            ]],
        ];
        foreach ($method['settings'] as $setting) {
            $definition = [
                'name' => $setting['name'],
                'type' => $setting['type'] ?? 'text',
                'label' => $setting['label'],
                'required' => !empty($setting['required']) && empty($setting['secret']),
                'value' => $method['values'][$setting['name']] ?? $setting['default'] ?? '',
            ];
            if (($setting['type'] ?? '') === 'select') {
                $definition['options'] = $setting['options'] ?? [];
            }
            if (!empty($setting['secret'])) {
                $definition['required'] = false;
                $definition['placeholder'] = 'Leave blank to keep the saved credential';
                $definition['value'] = '';
            }
            $definitions[] = $definition;
        }
        return $this->renderer->render(
            $request,
            '/stockflow/payment-methods/configure?id=' . rawurlencode($method['id']),
            $definitions,
            'post',
            'Save payment method'
        );
    }
}
