<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Controller;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Entity\User\UserVerification;
use Simp\Pindrop\Mail\MailManager;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\stockflow\src\Form\FormRenderer;
use Simp\Pindrop\Modules\stockflow\src\Form\StockflowFormFactory;
use Simp\Pindrop\Modules\stockflow\src\Payment\PaymentMethodRegistry;
use Simp\Pindrop\Modules\stockflow\src\Services\ManualOrderService;
use Simp\Pindrop\Modules\stockflow\src\Services\AdjustmentConfigurationService;
use Simp\Pindrop\Modules\stockflow\src\Services\StockflowService;
use Simp\Pindrop\Modules\stockflow\src\Services\ShippingMethodService;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StockflowController extends ControllerBase
{
    public function __construct(
        private readonly StockflowService $stockflow,
        private readonly StockflowFormFactory $forms,
        private readonly DatabaseService $database,
        private readonly ShippingMethodService $shippingMethods,
        private readonly PaymentMethodRegistry $paymentMethods,
        private readonly ManualOrderService $manualOrders,
        private readonly AdjustmentConfigurationService $adjustments
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new self(
            $container->get('stockflow.service'),
            new StockflowFormFactory(new FormRenderer()),
            $container->get('database'),
            $container->get('stockflow.shipping_methods'),
            $container->get('stockflow.payment_methods'),
            $container->get('stockflow.manual_orders'),
            $container->get('stockflow.adjustments')
        );
    }

    public function dashboard(Request $request, string $route_name, array $options): Response
    {
        return $this->page('dashboard.twig', [
            'page_title' => 'Dashboard',
            'dashboard' => $this->stockflow->getDashboardData(),
        ]);
    }

    public function inventory(Request $request, string $route_name, array $options): Response
    {
        return $this->page('inventory.twig', [
            'page_title' => 'Inventory',
            'inventory' => $this->stockflow->getInventory(),
            'currency' => $this->stockflow->getStoreSettings()['currency'],
        ]);
    }

    public function inventoryAdjustment(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') === 'adjust_inventory'
                && $this->process(
                    fn () => $this->stockflow->adjustInventory($request->request->all()),
                    'Stock movement recorded.'
                )) {
                return new RedirectResponse('/stockflow/inventory');
            }
            if ($request->request->get('_action') !== 'adjust_inventory') {
                Message::error('Unknown inventory form submission.');
            }
        }

        return $this->page('inventory-adjustment.twig', [
            'page_title' => 'Adjust inventory',
            'form_html' => $this->forms->inventoryAdjustment($request, $this->stockflow->getVariants()),
        ]);
    }

    public function products(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            if ($this->handleProductSubmission($request)) {
                return new RedirectResponse('/stockflow/products');
            }
        }

        $products = $this->stockflow->getProducts();
        foreach ($products as &$product) {
            $product['delete_url'] = '/stockflow/products/delete?id=' . (int) $product['id'];
        }
        unset($product);

        return $this->page('products.twig', [
            'page_title' => 'Products',
            'products' => $products,
        ]);
    }

    public function productDelete(Request $request, string $route_name, array $options): Response
    {
        $productId = $this->queryId($request, 'id');
        if ($productId === null) {
            Message::error('Select a valid product to delete.');
            return new RedirectResponse('/stockflow/products');
        }
        $product = $this->stockflow->getProduct($productId);
        if ($product === null) {
            Message::error('Product not found.');
            return new RedirectResponse('/stockflow/products');
        }
        if ($request->isMethod('POST')
            && $request->request->get('_action') === 'delete_product'
            && $this->process(fn () => $this->stockflow->deleteProduct($productId), 'Product deleted.')) {
            return new RedirectResponse('/stockflow/products');
        }

        return $this->page('product-delete.twig', [
            'page_title' => 'Delete product',
            'product' => $product,
            'form_html' => $this->forms->deleteProduct($request, $productId),
        ]);
    }

    public function productEdit(Request $request, string $route_name, array $options): Response
    {
        $productId = $this->queryId($request, 'id');
        if ($productId === null) {
            Message::error('Select a valid product to edit.');
            return new RedirectResponse('/stockflow/products');
        }
        if ($request->isMethod('POST')
            && $request->request->get('_action') === 'update_product'
            && $this->process(
                fn () => $this->stockflow->updateProduct($productId, $request->request->all()),
                'Product updated.'
            )) {
            return new RedirectResponse('/stockflow/products');
        }

        $product = $this->stockflow->getProduct($productId);
        if ($product === null) {
            Message::error('Product not found.');
            return new RedirectResponse('/stockflow/products');
        }

        return $this->page('product-edit.twig', [
            'page_title' => 'Edit product',
            'product' => $product,
            'form_html' => $this->forms->productEdit($request, $product, $this->stockflow->getCategories()),
        ]);
    }

    public function productVariants(Request $request, string $route_name, array $options): Response
    {
        $productId = $this->queryId($request, 'product_id');
        if ($productId === null) {
            Message::error('Select a valid product to manage variants.');
            return new RedirectResponse('/stockflow/products');
        }

        $product = $this->stockflow->getProduct($productId);
        if ($product === null) {
            Message::error('Product not found.');
            return new RedirectResponse('/stockflow/products');
        }

        $variants = $this->stockflow->getProductVariants($productId);

        return $this->page('product-variants.twig', [
            'page_title' => 'Product variants',
            'product' => $product,
            'variants' => $variants,
        ]);
    }

    public function variantAdd(Request $request, string $route_name, array $options): Response
    {
        $productId = $this->queryId($request, 'product_id');
        if ($productId === null) {
            Message::error('Select a valid product before adding a variant.');
            return new RedirectResponse('/stockflow/products');
        }
        $product = $this->stockflow->getProduct($productId);
        if ($product === null) {
            Message::error('Product not found.');
            return new RedirectResponse('/stockflow/products');
        }
        if ($request->isMethod('POST')
            && $request->request->get('_action') === 'create_variant') {
            $data = $request->request->all();
            $data['product_id'] = $productId;
            if ($this->process(fn () => $this->stockflow->createVariant($data), 'Product variant created.')) {
                return new RedirectResponse('/stockflow/products/variants?product_id=' . $productId);
            }
        } elseif ($request->isMethod('POST')) {
            Message::error('Unknown variant form submission.');
        }

        return $this->page('variant-add.twig', [
            'page_title' => 'Add product variant',
            'product' => $product,
            'form_html' => $this->forms->addVariant(
                $request,
                $productId,
                $this->stockflow->getStoreSettings()['currency']
            ),
        ]);
    }

    public function variantDelete(Request $request, string $route_name, array $options): Response
    {
        $variantId = $this->queryId($request, 'id');
        if ($variantId === null) {
            Message::error('Select a valid product variant to delete.');
            return new RedirectResponse('/stockflow/products');
        }
        $variant = $this->stockflow->getVariant($variantId);
        if ($variant === null) {
            Message::error('Product variant not found.');
            return new RedirectResponse('/stockflow/products');
        }
        $productId = (int) $variant['product_id'];
        if ($request->isMethod('POST')
            && $request->request->get('_action') === 'delete_variant'
            && $this->process(
                fn () => $this->stockflow->deleteVariant($variantId, $productId),
                'Product variant deleted.'
            )) {
            return new RedirectResponse('/stockflow/products/variants?product_id=' . $productId);
        }

        return $this->page('variant-delete.twig', [
            'page_title' => 'Delete variant',
            'product' => $this->stockflow->getProduct($productId),
            'variant' => $variant,
            'form_html' => $this->forms->deleteVariant($request, $variantId),
        ]);
    }

    public function variantEdit(Request $request, string $route_name, array $options): Response
    {
        $variantId = $this->queryId($request, 'id');
        if ($variantId === null) {
            Message::error('Select a valid product variant to edit.');
            return new RedirectResponse('/stockflow/products');
        }
        $variant = $this->stockflow->getVariant($variantId);
        if ($variant === null) {
            Message::error('Product variant not found.');
            return new RedirectResponse('/stockflow/products');
        }
        if ($request->isMethod('POST')
            && $request->request->get('_action') === 'update_variant'
            && $this->process(
                fn () => $this->stockflow->updateVariant($variantId, $request->request->all()),
                'Product variant updated.'
            )) {
            return new RedirectResponse('/stockflow/products/variants?product_id=' . (int) $variant['product_id']);
        }

        return $this->page('variant-edit.twig', [
            'page_title' => 'Edit product variant',
            'variant' => $variant,
            'form_html' => $this->forms->variantEdit(
                $request,
                $variant,
                $this->stockflow->getStoreSettings()['currency']
            ),
        ]);
    }

    public function productManagement(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('GET') && $request->query->get('reset_product_form') === '1') {
            $this->forms->clearProductWizard();
            return new RedirectResponse('/stockflow/products/manage');
        }

        $categories = $this->stockflow->getCategories();
        $currency = $this->stockflow->getStoreSettings()['currency'];
        $wizardHtml = null;

        if ($request->isMethod('POST')) {
            if ($request->request->has('_multistep_step')) {
                try {
                    $wizard = $this->forms->processProductWizard($request, $categories, $currency);
                    $wizardHtml = $wizard['html'];
                    if ($wizard['complete']) {
                        $image = $request->files->get('product_image');
                        if ($image !== null && !$image instanceof UploadedFile) {
                            throw new InvalidArgumentException('Upload a single product image file.');
                        }
                        if ($this->process(
                            fn () => $this->stockflow->createProduct($wizard['values'], $image),
                            'Product and its first variant were created.'
                        )) {
                            $this->forms->clearProductWizard();
                            return new RedirectResponse('/stockflow/products');
                        }
                        $this->forms->resetProductWizardCompletion();
                    }
                } catch (InvalidArgumentException $exception) {
                    Message::error($exception->getMessage());
                }
            } elseif ($this->handleProductSubmission($request)) {
                return new RedirectResponse('/stockflow/products');
            }
        }

        $forms = $this->forms->productForms(
            $request,
            $categories,
            $currency
        );
        if ($wizardHtml !== null) {
            $forms['wizard_html'] = $wizardHtml;
        }

        return $this->page('product-management.twig', [
            'page_title' => 'Manage products',
        ] + $forms);
    }

    public function orders(Request $request, string $route_name, array $options): Response
    {
        $filters = [
            'status' => $this->queryFilter($request, 'status'),
            'email' => $this->queryFilter($request, 'email'),
            'from' => $this->queryFilter($request, 'from'),
            'to' => $this->queryFilter($request, 'to'),
        ];

        try {
            $orders = $this->stockflow->getOrders($filters);
        } catch (InvalidArgumentException $exception) {
            Message::error($exception->getMessage());
            $orders = [];
        }

        return $this->page('orders.twig', [
            'page_title' => 'Orders',
            'orders' => $orders,
            'filters' => $filters,
            'filter_form' => $this->forms->orderFilter($request, $filters),
        ]);
    }

    public function orderView(Request $request, string $route_name, array $options): Response
    {
        $orderId = $this->queryId($request, 'id');
        $order = $orderId === null ? null : $this->manualOrders->getOrder($orderId);
        if ($order === null) {
            Message::error('Order not found.');
            return new RedirectResponse('/stockflow/orders');
        }
        return $this->page('order-view.twig', [
            'page_title' => 'View order',
            'order' => $order,
            'currency' => $order['currency_code'],
            'can_edit' => $order['status'] === 'pending'
                && array_reduce($order['payments'], static fn (bool $ok, array $payment): bool => $ok && $payment['status'] === 'failed', true),
            'can_request_payment' => $order['status'] === 'pending'
                && array_reduce($order['payments'], static fn (bool $ok, array $payment): bool => $ok && $payment['status'] === 'failed', true),
            'can_delete' => $order['status'] === 'pending'
                && array_reduce($order['payments'], static fn (bool $ok, array $payment): bool => $ok
                    && $payment['provider'] === 'manual'
                    && !in_array($payment['status'], ['paid', 'authorized'], true), true),
            'payment_request_form' => $this->forms->paymentRequestEmail($request, $orderId),
            'manual_payment_form' => $this->forms->manualPayment($request, $orderId),
            'delete_form' => $this->forms->orderDelete($request, $orderId),
        ]);
    }

    public function orderEdit(Request $request, string $route_name, array $options): Response
    {
        $orderId = $this->queryId($request, 'id');
        $order = $orderId === null ? null : $this->manualOrders->getOrder($orderId);
        if ($order === null) {
            Message::error('Order not found.');
            return new RedirectResponse('/stockflow/orders');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'update_order') {
                Message::error('Unknown order form submission.');
            } else {
                try {
                    $this->manualOrders->updatePendingOrder(
                        $orderId,
                        $request->request->all(),
                        $this->adjustments->getRules('taxes', true),
                        $this->adjustments->getRules('discounts', true)
                    );
                    Message::info('Order updated. Send the customer a new payment request when ready.');
                    return new RedirectResponse('/stockflow/orders/view?id=' . $orderId);
                } catch (InvalidArgumentException $exception) {
                    Message::error($exception->getMessage());
                    $order = array_merge($order, $request->request->all());
                }
            }
        }
        $shippingMethods = array_values(array_filter(
            $this->shippingMethods->getMethods(),
            static fn (array $method): bool => ($method['status'] ?? '') === 'active'
        ));
        return $this->page('order-edit.twig', [
            'page_title' => 'Edit order',
            'order' => $order,
            'form_html' => $this->forms->orderEdit(
                $request,
                $order,
                $this->manualOrders->getVariants(),
                $shippingMethods,
                $this->adjustments->getRules('taxes', true),
                $this->adjustments->getRules('discounts', true)
            ),
        ]);
    }

    public function orderDelete(Request $request, string $route_name, array $options): Response
    {
        $orderId = $this->queryId($request, 'id');
        $order = $orderId === null ? null : $this->manualOrders->getOrder($orderId);
        if ($order === null) {
            Message::error('Order not found.');
            return new RedirectResponse('/stockflow/orders');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'delete_order') {
                Message::error('Unknown order deletion submission.');
            } else {
                try {
                    $this->manualOrders->deletePendingOrder($orderId);
                    Message::info('Pending unpaid order deleted and its reserved stock was released.');
                    return new RedirectResponse('/stockflow/orders');
                } catch (InvalidArgumentException|\RuntimeException $exception) {
                    Message::error($exception->getMessage());
                    return new RedirectResponse('/stockflow/orders/view?id=' . $orderId);
                }
            }
        }
        return $this->page('order-delete.twig', [
            'page_title' => 'Delete order',
            'order' => $order,
            'form_html' => $this->forms->orderDelete($request, $orderId),
        ]);
    }

    public function orderPaymentRequest(Request $request, string $route_name, array $options): Response
    {
        $orderId = $this->queryId($request, 'id');
        $order = $orderId === null ? null : $this->manualOrders->getOrder($orderId);
        if ($order === null) {
            Message::error('Order not found.');
            return new RedirectResponse('/stockflow/orders');
        }
        if ($request->request->get('_action') !== 'send_payment_request') {
            Message::error('Unknown payment request submission.');
            return new RedirectResponse('/stockflow/orders/view?id=' . $orderId);
        }
        try {
            if ($order['status'] !== 'pending') {
                throw new InvalidArgumentException('Payment requests can only be sent for pending orders.');
            }
            foreach ($order['payments'] as $payment) {
                if (in_array($payment['status'], ['paid', 'authorized'], true)
                    || $payment['status'] === 'pending') {
                    throw new InvalidArgumentException('An unresolved or completed payment already exists for this order.');
                }
            }
            $expires = time() + 7 * 24 * 60 * 60;
            $signature = $this->paymentLinkSignature($orderId, $expires);
            $paymentLink = $request->getSchemeAndHttpHost() . '/stockflow/pay?order=' . $orderId
                . '&expires=' . $expires . '&signature=' . rawurlencode($signature);
            $content = $this->renderTwig('@stockflow/order-payment-request.twig', [
                'order' => $order,
                'payment_link' => $paymentLink,
                'store_name' => $this->stockflow->getStoreSettings()['store_name'],
            ]);
            $mailManager = getAppContainer()->get('mail.manager');
            if (!$mailManager->sendHtml(
                $order['email'],
                'Payment request for order ' . $order['order_number'],
                $content->getContent()
            )) {
                throw new \RuntimeException('Mail manager reported that payment request delivery failed.');
            }
            Message::info('Secure payment request sent to ' . $order['email'] . '.');
        } catch (InvalidArgumentException $exception) {
            Message::error($exception->getMessage());
        } catch (\Throwable $exception) {
            getAppContainer()->get('logger')->error('Stockflow payment request email failed', [
                'order_number' => $order['order_number'],
                'exception' => $exception->getMessage(),
            ]);
            Message::error('The payment request could not be sent. Check the mail configuration and payment-link secret.');
        }
        return new RedirectResponse('/stockflow/orders/view?id=' . $orderId);
    }

    public function manualPayment(Request $request, string $route_name, array $options): Response
    {
        $orderId = $this->queryId($request, 'id');
        $order = $orderId === null ? null : $this->manualOrders->getOrder($orderId);
        if ($order === null) {
            Message::error('Order not found.');
            return new RedirectResponse('/stockflow/orders');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'create_manual_payment') {
                Message::error('Unknown manual payment submission.');
            } else {
                try {
                    $paymentId = $this->manualOrders->createManualPayment(
                        $orderId,
                        (string) $request->request->get('provider_payment_id', '')
                    );
                    Message::info('Offline payment record created. Confirm receipt after verifying funds.');
                    return new RedirectResponse('/stockflow/payments/receive?id=' . $paymentId);
                } catch (InvalidArgumentException $exception) {
                    Message::error($exception->getMessage());
                }
            }
        }
        return $this->page('manual-payment-create.twig', [
            'page_title' => 'Record offline payment',
            'order' => $order,
            'form_html' => $this->forms->manualPayment($request, $orderId),
        ]);
    }

    public function payments(Request $request, string $route_name, array $options): Response
    {
        $payments = $this->database->table('payments', 'stockflow')
            ->select([
                'payments.id',
                'payments.order_id',
                'payments.provider',
                'payments.provider_payment_id',
                'payments.status',
                'payments.amount',
                'payments.currency_code',
                'payments.paid_at',
                'payments.created_at',
                'orders.order_number',
                'orders.email',
            ])
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->orderBy('payments.created_at', 'DESC')
            ->limit(200)
            ->get();
        return $this->page('payments.twig', [
            'page_title' => 'Payments',
            'payments' => $payments,
        ]);
    }

    public function paymentEdit(Request $request, string $route_name, array $options): Response
    {
        $paymentId = $this->queryId($request, 'id');
        $payment = $paymentId === null ? null : $this->manualOrders->getPayment($paymentId);
        if ($payment === null) {
            Message::error('Payment not found.');
            return new RedirectResponse('/stockflow/payments');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'edit_payment') {
                Message::error('Unknown payment form submission.');
            } else {
                try {
                    $this->manualOrders->updatePendingManualPayment(
                        $paymentId,
                        (string) $request->request->get('provider_payment_id', '')
                    );
                    Message::info('Pending manual payment reference updated.');
                    return new RedirectResponse('/stockflow/payments');
                } catch (InvalidArgumentException $exception) {
                    Message::error($exception->getMessage());
                }
            }
        }
        return $this->page('payment-edit.twig', [
            'page_title' => 'Edit payment',
            'payment' => $payment,
            'form_html' => $this->forms->paymentReference(
                $request,
                $paymentId,
                (string) ($payment['provider_payment_id'] ?? '')
            ),
        ]);
    }

    public function paymentDelete(Request $request, string $route_name, array $options): Response
    {
        $paymentId = $this->queryId($request, 'id');
        $payment = $paymentId === null ? null : $this->manualOrders->getPayment($paymentId);
        if ($payment === null) {
            Message::error('Payment not found.');
            return new RedirectResponse('/stockflow/payments');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'delete_payment') {
                Message::error('Unknown payment deletion submission.');
            } else {
                try {
                    $this->manualOrders->deletePendingManualPayment($paymentId);
                    Message::info('Pending manual payment record deleted.');
                    return new RedirectResponse('/stockflow/payments');
                } catch (InvalidArgumentException $exception) {
                    Message::error($exception->getMessage());
                    return new RedirectResponse('/stockflow/payments');
                }
            }
        }
        return $this->page('payment-delete.twig', [
            'page_title' => 'Delete payment',
            'payment' => $payment,
            'form_html' => $this->forms->paymentDelete($request, $paymentId),
        ]);
    }

    public function paymentReceive(Request $request, string $route_name, array $options): Response
    {
        $paymentId = $this->queryId($request, 'id');
        $payment = $paymentId === null ? null : $this->manualOrders->getPayment($paymentId);
        if ($payment === null) {
            Message::error('Payment not found.');
            return new RedirectResponse('/stockflow/payments');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'receive_payment') {
                Message::error('Unknown payment-receipt submission.');
            } else {
                try {
                    $this->manualOrders->receiveManualPayment($paymentId);
                    Message::info('Payment received; the order was confirmed and reserved stock was decremented.');
                    return new RedirectResponse('/stockflow/orders/view?id=' . (int) $payment['order_id']);
                } catch (InvalidArgumentException|\RuntimeException $exception) {
                    Message::error($exception->getMessage());
                    return new RedirectResponse('/stockflow/payments');
                }
            }
        }
        return $this->page('payment-receive.twig', [
            'page_title' => 'Receive payment',
            'payment' => $payment,
            'form_html' => $this->forms->receiveManualPayment($request, $paymentId),
        ]);
    }

    public function paymentReconcile(Request $request, string $route_name, array $options): Response
    {
        $paymentId = $this->queryId($request, 'id');
        $payment = $paymentId === null ? null : $this->manualOrders->getPayment($paymentId);
        if ($payment === null) {
            Message::error('Payment not found.');
            return new RedirectResponse('/stockflow/payments');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'reconcile_payment'
                || $request->request->get('confirm_gateway_capture') !== 'yes') {
                Message::error('Confirm that the transaction is captured in the gateway before reconciling.');
            } else {
                try {
                    $this->manualOrders->reconcileGatewayPayment(
                        $paymentId,
                        (string) $request->request->get('provider_payment_id', '')
                    );
                    Message::info('Gateway payment reconciled and order confirmed.');
                    return new RedirectResponse('/stockflow/orders/view?id=' . (int) $payment['order_id']);
                } catch (InvalidArgumentException|\RuntimeException $exception) {
                    Message::error($exception->getMessage());
                }
            }
        }
        return $this->page('payment-reconcile.twig', [
            'page_title' => 'Reconcile gateway payment',
            'payment' => $payment,
            'form_html' => $this->forms->gatewayReconciliation(
                $request,
                $paymentId,
                (string) ($payment['provider_payment_id'] ?? '')
            ),
        ]);
    }

    public function customerPayment(Request $request, string $route_name, array $options): Response
    {
        $orderId = $this->queryId($request, 'order');
        $expires = filter_var($request->query->get('expires'), FILTER_VALIDATE_INT);
        $signature = $request->query->get('signature');
        if (
            $orderId === null
            || $expires === false
            || !is_string($signature)
            || $expires < time()
            || $expires > time() + 7 * 24 * 60 * 60
        ) {
            return $this->renderTwig('@stockflow/customer-payment.twig', [
                'page_title' => 'Payment link unavailable',
                'error_message' => 'This payment link is invalid or has expired. Contact the store for a new payment request.',
            ], 403);
        }
        try {
            $expectedSignature = $this->paymentLinkSignature($orderId, $expires);
        } catch (\RuntimeException $exception) {
            getAppContainer()->get('logger')->error('Customer payment link secret is not configured', [
                'exception' => $exception->getMessage(),
            ]);
            return $this->renderTwig('@stockflow/customer-payment.twig', [
                'page_title' => 'Payments temporarily unavailable',
                'error_message' => 'Online payment is temporarily unavailable. Contact the store for assistance.',
            ], 503);
        }
        if (!hash_equals($expectedSignature, $signature)) {
            return $this->renderTwig('@stockflow/customer-payment.twig', [
                'page_title' => 'Payment link unavailable',
                'error_message' => 'This payment link is invalid or has expired. Contact the store for a new payment request.',
            ], 403);
        }

        $order = $this->manualOrders->getOrder($orderId);
        if ($order === null || $order['status'] !== 'pending') {
            return $this->renderTwig('@stockflow/customer-payment.twig', [
                'page_title' => 'Payment link unavailable',
                'error_message' => 'This order is not awaiting payment. Contact the store if you need assistance.',
            ], 404);
        }

        foreach ($order['payments'] as $payment) {
            if (in_array($payment['status'], ['paid', 'authorized'], true)
                || $payment['status'] === 'pending') {
                return $this->renderTwig('@stockflow/customer-payment.twig', [
                    'page_title' => 'Payment needs attention',
                    'order' => $order,
                    'error_message' => 'A payment attempt for this order is already pending or completed. Do not retry payment; contact the store to confirm its status.',
                ]);
            }
        }

        $currency = (string) $order['currency_code'];
        $methods = array_values(array_filter(
            $this->paymentMethods->getMethods(),
            static fn (array $method): bool => $method['enabled']
                && $method['configured']
                && in_array($currency, $method['currencies'], true)
        ));
        $authorizeClient = null;
        foreach ($methods as &$method) {
            if ($method['id'] === 'authorize_net') {
                $method['authorize_client'] = $this->paymentMethods->authorizeNetClientConfiguration();
                $authorizeClient = $method['authorize_client'];
            }
        }
        unset($method);

        $notice = null;
        if ($request->isMethod('POST')) {
            $provider = $request->request->get('payment_method');
            $paymentData = $request->request->all('payment_data');
            $attempt = null;
            if (!is_string($provider) || !is_array($paymentData)) {
                $notice = 'Select a payment method and provide its required details.';
            } else {
                try {
                    if ($provider === 'airtel_money') {
                        $paymentData['transaction_id'] = $order['order_number'] . '-' . bin2hex(random_bytes(5));
                    }
                    $this->paymentMethods->validateCharge($provider, $currency, $paymentData);
                    $attempt = $this->manualOrders->createGatewayPayment($orderId, $provider);
                } catch (InvalidArgumentException $exception) {
                    $notice = $exception->getMessage();
                }
                if ($attempt !== null && $notice === null) {
                    try {
                        $result = $this->paymentMethods->charge(
                            $provider,
                            $attempt['amount'],
                            $currency,
                            $paymentData + ['order_number' => $attempt['order_number']]
                        );
                    } catch (InvalidArgumentException $exception) {
                        $this->manualOrders->recordGatewayPaymentAttempt($attempt['id'], [
                            'status' => 'failed',
                            'provider_payment_id' => null,
                        ]);
                        $notice = $exception->getMessage();
                    } catch (\Throwable $exception) {
                        getAppContainer()->get('logger')->error('Customer payment status could not be confirmed', [
                            'order_number' => $order['order_number'],
                            'provider' => $provider,
                            'exception' => $exception->getMessage(),
                        ]);
                        $notice = 'Payment status could not be confirmed. Do not submit another payment. Contact the store with order number '
                            . $order['order_number'] . '.';
                    }
                    if (isset($result)) {
                        try {
                            $this->manualOrders->recordGatewayPaymentAttempt($attempt['id'], $result);
                            $notice = match ($result['status']) {
                                'paid' => 'Payment received. The order is confirmed.',
                                'failed' => 'The payment was declined. You may try again with another method.',
                                'authorized' => 'Payment is authorized but not settled. Do not try again; contact the store.',
                                default => 'The payment request is pending confirmation. Do not try again; contact the store if it remains pending.',
                            };
                        } catch (\Throwable $exception) {
                            getAppContainer()->get('logger')->error('Customer payment result could not be recorded', [
                                'order_number' => $order['order_number'],
                                'provider' => $provider,
                                'provider_payment_id' => $result['provider_payment_id'] ?? null,
                                'exception' => $exception->getMessage(),
                            ]);
                            $notice = 'Payment status could not be confirmed. Do not submit another payment. Contact the store with order number '
                                . $order['order_number'] . '.';
                        }
                    }
                }
                $order = $this->manualOrders->getOrder($orderId) ?? $order;
                if ($order['status'] !== 'pending') {
                    $methods = [];
                }
            }
        }

        return $this->renderTwig('@stockflow/customer-payment.twig', [
            'page_title' => 'Pay for order',
            'order' => $order,
            'currency' => $currency,
            'payment_methods' => $methods,
            'authorize_client' => $authorizeClient,
            'form_html' => $methods === []
                ? ''
                : $this->forms->customerPayment($request, $methods, $request->request->all()),
            'notice' => $notice,
        ]);
    }

    public function manualOrderCreate(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('GET') && $request->query->get('reset_order_form') === '1') {
            $this->forms->clearManualOrderWizard();
            return new RedirectResponse('/stockflow/orders/create');
        }

        if ($request->isMethod('POST')) {
            if (!$request->request->has('_multistep_step')) {
                Message::error('Unknown manual order form submission.');
            } else {
                try {
                    $context = $this->manualOrderContext();
                    $wizard = $this->forms->processManualOrderWizard(
                        $request,
                        $context['variants'],
                        $context['shipping_methods'],
                        $context['payment_methods'],
                        $context['currency'],
                        $context['tax_rules'],
                        $context['discount_rules']
                    );
                    if (!$wizard['complete']) {
                        return $this->manualOrderForm($request);
                    }
                    $data = $wizard['values'];
                    //dump($data, $wizard);
                    $paymentData = $data['payment_data'] ?? [];
                    if (!is_array($paymentData)) {
                        throw new InvalidArgumentException('Payment details must be valid.');
                    }
                    $this->paymentMethods->validateCharge(
                        (string) ($data['payment_method'] ?? ''),
                        $context['currency'],
                        $paymentData
                    );
                    $order = $this->manualOrders->createOrder(
                        $data,
                        $context['tax_rules'],
                        $context['discount_rules']
                    );
                } catch (InvalidArgumentException $exception) {
                    $this->forms->resetManualOrderWizardCompletion();
                    $this->forms->clearManualOrderPaymentData();
                    Message::error($exception->getMessage());
                    return $this->manualOrderForm($request);
                } catch (\RuntimeException $exception) {
                    $this->forms->resetManualOrderWizardCompletion();
                    $this->forms->clearManualOrderPaymentData();
                    getAppContainer()->get('logger')->error('Manual order setup failed before order creation', [
                        'error' => $exception->getMessage(),
                    ]);
                    Message::error('Payment setup is unavailable. Check the gateway configuration and encryption key, then try again.');
                    return $this->manualOrderForm($request);
                }

                $this->forms->clearManualOrderWizard();
                try {
                    $paymentResult = $this->paymentMethods->charge(
                        $order['payment_method'],
                        $order['amount'],
                        $order['currency'],
                        $order['payment_data'] + ['order_number' => $order['order_number']]
                    );
                } catch (InvalidArgumentException $exception) {
                    $this->manualOrders->recordPaymentResult($order['id'], [
                        'status' => 'failed',
                        'provider_payment_id' => null,
                    ]);
                    getAppContainer()->get('logger')->warning('Manual order payment was not submitted', [
                        'order_number' => $order['order_number'],
                        'error' => $exception->getMessage(),
                    ]);
                    Message::error('Order ' . $order['order_number'] . ' was created, but payment could not be submitted. Its stock reservation was released.');
                    return new RedirectResponse('/stockflow/orders');
                } catch (\Throwable $exception) {
                    getAppContainer()->get('logger')->error('Manual order payment result could not be confirmed', [
                        'order_number' => $order['order_number'],
                        'error' => $exception->getMessage(),
                    ]);
                    Message::error('Order ' . $order['order_number'] . ' was saved, but payment status could not be confirmed. The order remains pending and its stock is reserved; reconcile the payment with the gateway before retrying.');
                    return new RedirectResponse('/stockflow/orders');
                }

                try {
                    $this->manualOrders->recordPaymentResult($order['id'], $paymentResult);
                } catch (\Throwable $exception) {
                    getAppContainer()->get('logger')->error('Manual order payment result could not be persisted', [
                        'order_number' => $order['order_number'],
                        'provider_payment_id' => $paymentResult['provider_payment_id'] ?? null,
                        'error' => $exception->getMessage(),
                    ]);
                    Message::error('Order ' . $order['order_number'] . ' was saved, but payment status could not be confirmed. The order remains pending and its stock is reserved; reconcile the payment with the gateway before retrying.');
                    return new RedirectResponse('/stockflow/orders');
                }
                if ($paymentResult['status'] === 'failed') {
                    Message::error('Order ' . $order['order_number'] . ' was created, but the payment was declined. Its stock reservation was released.');
                } elseif ($paymentResult['status'] === 'pending') {
                    Message::info('Order ' . $order['order_number'] . ' was created and the payment request is pending customer approval. Its stock is reserved.');
                } elseif ($paymentResult['status'] === 'authorized') {
                    Message::info('Order ' . $order['order_number'] . ' was created. Payment is authorized but not settled; its stock remains reserved pending reconciliation.');
                } else {
                    Message::info('Order ' . $order['order_number'] . ' was created and payment was ' . $paymentResult['status'] . '.');
                }
                return new RedirectResponse('/stockflow/orders');
            }
        }

        return $this->manualOrderForm($request);
    }

    private function manualOrderForm(Request $request): Response
    {
        $context = $this->manualOrderContext();
        return $this->page('order-create.twig', [
            'page_title' => 'Create manual order',
            'has_order_form_state' => $this->forms->hasManualOrderWizardState(),
            'currency' => $context['currency'],
            'payment_methods' => $context['payment_methods'],
            'shipping_method_count' => count($context['shipping_methods']),
            'variant_count' => count($context['variants']),
            'form_html' => $this->forms->manualOrder(
                $request,
                $context['variants'],
                $context['shipping_methods'],
                $context['payment_methods'],
                $context['currency'],
                $context['tax_rules'],
                $context['discount_rules']
            ),
        ]);
    }

    private function manualOrderContext(): array
    {
        $currency = $this->stockflow->getStoreSettings()['currency'];
        $shippingMethods = array_values(array_filter(
            $this->shippingMethods->getMethods(),
            static fn (array $method): bool => ($method['status'] ?? '') === 'active'
        ));
        $paymentMethods = array_values(array_filter(
            $this->paymentMethods->getMethods(),
            fn (array $method): bool => $method['enabled']
                && $method['configured']
                && in_array($currency, $method['currencies'], true)
        ));
        foreach ($paymentMethods as &$paymentMethod) {
            if ($paymentMethod['id'] === 'authorize_net') {
                $paymentMethod['authorize_client'] = $this->paymentMethods->authorizeNetClientConfiguration();
            }
        }
        unset($paymentMethod);
        $variants = $this->manualOrders->getVariants();

        return [
            'currency' => $currency,
            'payment_methods' => $paymentMethods,
            'tax_rules' => $this->adjustments->getRules('taxes', true),
            'discount_rules' => $this->adjustments->getRules('discounts', true),
            'shipping_methods' => $shippingMethods,
            'variants' => $variants,
        ];
    }

    public function purchasing(Request $request, string $route_name, array $options): Response
    {
        $suppliers = $this->stockflow->getSuppliers();
        $purchaseOrders = $this->stockflow->getPurchaseOrders();
        foreach ($purchaseOrders as &$purchaseOrder) {
            if ($purchaseOrder['receivable_quantity'] > 0 && !in_array($purchaseOrder['status'], ['cancelled', 'received'], true)) {
                $purchaseOrder['receive_url'] = '/stockflow/purchasing/receive?id=' . (int) $purchaseOrder['id'];
            }
            if ($purchaseOrder['status'] === 'submitted' && (int) $purchaseOrder['received_quantity'] === 0) {
                $purchaseOrder['edit_url'] = '/stockflow/purchasing/edit?id=' . (int) $purchaseOrder['id'];
            }
        }
        unset($purchaseOrder);

        return $this->page('purchasing.twig', [
            'page_title' => 'Purchasing',
            'purchase_orders' => $purchaseOrders,
            'supplier_count' => count($suppliers),
        ]);
    }

    public function purchaseOrderCreate(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            $purchaseOrderId = null;
            if ($request->request->get('_action') === 'create_purchase_order'
                && $this->process(
                    function () use (&$purchaseOrderId, $request): void {
                        $purchaseOrderId = $this->stockflow->createPurchaseOrder($request->request->all());
                    },
                    'Purchase order created.'
                )) {
                $purchaseOrder = $purchaseOrderId === null
                    ? null
                    : $this->stockflow->getPurchaseOrder($purchaseOrderId);
                if ($purchaseOrder !== null) {
                    $this->notifySupplier($purchaseOrder, 'created');
                }
                return new RedirectResponse('/stockflow/purchasing');
            }
            if ($request->request->get('_action') !== 'create_purchase_order') {
                Message::error('Unknown purchase-order form submission.');
            }
        }

        return $this->page('purchase-order-create.twig', [
            'page_title' => 'Create purchase order',
            'form_html' => $this->forms->purchaseOrderForm(
                $request,
                $this->stockflow->getSuppliers(),
                $this->stockflow->getVariants(),
                $this->stockflow->getStoreSettings()['currency']
            ),
        ]);
    }

    public function purchaseOrderEdit(Request $request, string $route_name, array $options): Response
    {
        $purchaseOrderId = $this->queryId($request, 'id');
        if ($purchaseOrderId === null) {
            Message::error('Select a valid purchase order to edit.');
            return new RedirectResponse('/stockflow/purchasing');
        }
        $purchaseOrder = $this->stockflow->getPurchaseOrder($purchaseOrderId);
        if ($purchaseOrder === null) {
            Message::error('Purchase order not found.');
            return new RedirectResponse('/stockflow/purchasing');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'update_purchase_order') {
                Message::error('Unknown purchase-order form submission.');
            } elseif ($this->process(
                fn () => $this->stockflow->updatePurchaseOrder($purchaseOrderId, $request->request->all()),
                'Purchase order updated.'
            )) {
                $updatedOrder = $this->stockflow->getPurchaseOrder($purchaseOrderId);
                if ($updatedOrder !== null) {
                    $this->notifySupplier($updatedOrder, 'updated');
                }
                return new RedirectResponse('/stockflow/purchasing');
            }
            $purchaseOrder = $this->stockflow->getPurchaseOrder($purchaseOrderId) ?? $purchaseOrder;
        }
        if ($purchaseOrder['status'] !== 'submitted'
            || array_sum(array_map(static fn (array $item): int => (int) $item['quantity_received'], $purchaseOrder['items'])) > 0) {
            Message::error('Only purchase orders with no received stock can be edited.');
            return new RedirectResponse('/stockflow/purchasing');
        }

        return $this->page('purchase-order-edit.twig', [
            'page_title' => 'Edit purchase order',
            'purchase_order' => $purchaseOrder,
            'form_html' => $this->forms->purchaseOrderEdit(
                $request,
                $purchaseOrder,
                $this->stockflow->getSuppliers(),
                $this->stockflow->getVariants(),
                $this->stockflow->getStoreSettings()['currency']
            ),
        ]);
    }

    public function purchaseOrderReceive(Request $request, string $route_name, array $options): Response
    {
        $purchaseOrderId = $this->queryId($request, 'id');
        if ($purchaseOrderId === null) {
            Message::error('Select a valid purchase order to receive.');
            return new RedirectResponse('/stockflow/purchasing');
        }
        $purchaseOrder = $this->stockflow->getPurchaseOrder($purchaseOrderId);
        if ($purchaseOrder === null) {
            Message::error('Purchase order not found.');
            return new RedirectResponse('/stockflow/purchasing');
        }
        if ($request->isMethod('POST')
            && $request->request->get('_action') === 'receive_purchase_order') {
            $data = $request->request->all();
            $data['purchase_order_id'] = $purchaseOrderId;
            if ($this->process(
                fn () => $this->stockflow->receivePurchaseOrder($data),
                'Stock received against purchase order.'
            )) {
                $receivedOrder = $this->stockflow->getPurchaseOrder($purchaseOrderId);
                if ($receivedOrder !== null) {
                    $this->notifySupplier($receivedOrder, 'received', (int) ($data['quantity_received'] ?? 0));
                }
                return new RedirectResponse('/stockflow/purchasing');
            }
            $purchaseOrder = $this->stockflow->getPurchaseOrder($purchaseOrderId) ?? $purchaseOrder;
        } elseif ($request->isMethod('POST')) {
            Message::error('Unknown purchase-order receiving form submission.');
        }
        if ($purchaseOrder['receivable_quantity'] < 1
            || in_array($purchaseOrder['status'], ['cancelled', 'received'], true)) {
            Message::error('This purchase order has no outstanding quantity to receive.');
            return new RedirectResponse('/stockflow/purchasing');
        }

        return $this->page('purchase-order-receive.twig', [
            'page_title' => 'Receive purchase order',
            'purchase_order' => $purchaseOrder,
            'form_html' => $this->forms->receivePurchaseOrder($request, $purchaseOrder),
        ]);
    }

    public function suppliers(Request $request, string $route_name, array $options): Response
    {
        return $this->page('suppliers.twig', [
            'page_title' => 'Suppliers',
            'suppliers' => $this->stockflow->getSuppliers(false),
        ]);
    }

    public function supplierCreate(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') === 'create_supplier'
                && $this->process(
                    fn () => $this->stockflow->createSupplier($request->request->all()),
                    'Supplier created.'
                )) {
                return new RedirectResponse('/stockflow/suppliers');
            }
            if ($request->request->get('_action') !== 'create_supplier') {
                Message::error('Unknown supplier form submission.');
            }
        }

        return $this->page('supplier-create.twig', [
            'page_title' => 'Add supplier',
            'form_html' => $this->forms->supplierForm($request),
        ]);
    }

    public function supplierEdit(Request $request, string $route_name, array $options): Response
    {
        $supplierId = $this->queryId($request, 'id');
        if ($supplierId === null) {
            Message::error('Select a valid supplier to edit.');
            return new RedirectResponse('/stockflow/suppliers');
        }
        $supplier = $this->stockflow->getSupplier($supplierId);
        if ($supplier === null) {
            Message::error('Supplier not found.');
            return new RedirectResponse('/stockflow/suppliers');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'update_supplier') {
                Message::error('Unknown supplier form submission.');
            } elseif ($this->process(
                fn () => $this->stockflow->updateSupplier($supplierId, $request->request->all()),
                'Supplier updated.'
            )) {
                return new RedirectResponse('/stockflow/suppliers');
            }
            $supplier = $this->stockflow->getSupplier($supplierId) ?? $supplier;
        }

        return $this->page('supplier-edit.twig', [
            'page_title' => 'Edit supplier',
            'form_html' => $this->forms->supplierEdit($request, $supplier),
        ]);
    }

    public function customers(Request $request, string $route_name, array $options): Response
    {
        return $this->page('customers.twig', [
            'page_title' => 'Customers',
            'customers' => $this->stockflow->getCustomers(),
        ]);
    }

    public function customerCreate(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'create_customer') {
                Message::error('Unknown customer form submission.');
            } else {
                try {
                    $customerData = $this->stockflow->validateCustomerData($request->request->all());
                    $logger = getAppContainer()->get('logger');
                    $result = $this->database->transaction(function () use ($customerData, $request, $logger): array {
                        $user = User::loadByEmail($customerData['email'], $this->database);
                        $newUser = $user === null;
                        $verification = null;
                        if ($newUser) {
                            $user = new User([], $this->database, $logger);
                            $user->setUsername($this->customerUsername($customerData['email']));
                            $user->setEmail($customerData['email']);
                            $user->setFirstName($customerData['first_name']);
                            $user->setLastName($customerData['last_name']);
                            $user->setPhone($customerData['phone']);
                            $user->setPassword($customerData['email'] . '.1234');
                            $user->setRole('customer');
                            $user->setStatus(User::STATUS_INACTIVE);
                            if (!$user->save() || $user->getId() === null) {
                                throw new \RuntimeException('The customer user account could not be created.');
                            }

                            $verification = UserVerification::createEmailVerificationToken(
                                $this->database,
                                $logger,
                                $user->getId(),
                                $user->getEmail(),
                                $request->getClientIp(),
                                $request->headers->get('User-Agent')
                            );
                            if ($verification === null) {
                                throw new \RuntimeException('The customer account verification token could not be created.');
                            }
                        }

                        if ($user->getId() === null) {
                            throw new \RuntimeException('The customer user ID could not be resolved.');
                        }
                        $customerId = $this->stockflow->createCustomer($customerData, $user->getId());
                        return [
                            'customer_id' => $customerId,
                            'user' => $user,
                            'new_user' => $newUser,
                            'verification' => $verification,
                        ];
                    });

                    if ($result['new_user']) {
                        $emailError = null;
                        try {
                            $emailContent = $this->renderTwig('@admin/emails/verify_email.twig', [
                                'user' => $result['user'],
                                'verification_link' => $request->getSchemeAndHttpHost() . Url::routeByName('user.verify_email', [
                                    'token' => $result['verification']->getToken(),
                                ]),
                            ]);
                            /** @var MailManager $mailManager */
                            $mailManager = getAppContainer()->get('mail.manager');
                            $emailSent = $mailManager->sendHtml(
                                $result['user']->getEmail(),
                                $_ENV['MAIL_VERIFICATION_SUBJECT'] ?? 'Verify your email',
                                (string) $emailContent->getContent()
                            );
                        } catch (\Throwable $exception) {
                            $emailError = $exception->getMessage();
                            $emailSent = false;
                        }

                        if (!$emailSent) {
                            $logger->error('Customer account verification email could not be sent', [
                                'user_id' => $result['user']->getId(),
                                'email' => $result['user']->getEmail(),
                                'error' => $emailError,
                            ]);
                            Message::warn('Customer and inactive user were created, but the verification email could not be sent.');
                        } else {
                            Message::info('Customer and inactive user were created. A verification email was sent.');
                        }
                    } else {
                        Message::info('Customer created and linked to the existing user account.');
                    }

                    return new RedirectResponse('/stockflow/customers');
                } catch (InvalidArgumentException $exception) {
                    Message::error($exception->getMessage());
                } catch (DatabaseException|\RuntimeException $exception) {
                    getAppContainer()->get('logger')->error('Customer creation failed', [
                        'error' => $exception->getMessage(),
                    ]);
                    Message::error('Customer and user account could not be created. Please check the account details and try again.');
                }
            }
        }

        return $this->page('customer-create.twig', [
            'page_title' => 'Add customer',
            'form_html' => $this->forms->customer($request),
        ]);
    }

    public function settings(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            if ($this->process(fn () => $this->stockflow->saveStoreSettings($request->request->all()), 'Stockflow settings saved.')) {
                return new RedirectResponse('/stockflow/settings');
            }
        }
        $settings = $this->stockflow->getStoreSettings();

        return $this->page('settings.twig', [
            'page_title' => 'Settings',
            'settings' => $settings,
            'store_name' => $settings['store_name'],
            'form_html' => $this->forms->settings($request, $settings),
        ]);
    }

    public function taxes(Request $request, string $route_name, array $options): Response
    {
        return $this->adjustmentRulesPage('taxes', 'Taxes');
    }

    public function discounts(Request $request, string $route_name, array $options): Response
    {
        return $this->adjustmentRulesPage('discounts', 'Discounts');
    }

    public function taxCreate(Request $request, string $route_name, array $options): Response
    {
        return $this->adjustmentRuleForm($request, 'taxes', null);
    }

    public function taxEdit(Request $request, string $route_name, array $options): Response
    {
        return $this->adjustmentRuleForm($request, 'taxes', $request->query->get('id'));
    }

    public function discountCreate(Request $request, string $route_name, array $options): Response
    {
        return $this->adjustmentRuleForm($request, 'discounts', null);
    }

    public function discountEdit(Request $request, string $route_name, array $options): Response
    {
        return $this->adjustmentRuleForm($request, 'discounts', $request->query->get('id'));
    }

    private function adjustmentRulesPage(string $kind, string $title): Response
    {
        $productsById = [];
        foreach ($this->stockflow->getProducts() as $product) {
            $productsById[(string) $product['id']] = (string) $product['name'];
        }
        $rules = $this->adjustments->getRules($kind);
        foreach ($rules as &$rule) {
            $rule['product_name'] = $productsById[(string) ($rule['product_id'] ?? '')] ?? null;
        }
        unset($rule);
        return $this->page('adjustment-rules.twig', [
            'page_title' => $title,
            'adjustment_kind' => $kind,
            'adjustment_rules' => $rules,
            'currency' => $this->stockflow->getStoreSettings()['currency'],
        ]);
    }

    private function adjustmentRuleForm(Request $request, string $kind, mixed $rawId): Response
    {
        $kindLabel = $kind === 'taxes' ? 'tax' : 'discount';
        $products = $this->stockflow->getProducts();
        $productIds = array_map(static fn (array $product): int => (int) $product['id'], $products);
        $id = null;
        if ($rawId !== null) {
            if (!is_string($rawId) || $rawId === '') {
                Message::error('Select a valid ' . $kindLabel . ' rule.');
                return new RedirectResponse('/stockflow/' . $kind);
            }
            $id = $rawId;
        }
        $rule = $id === null ? null : $this->adjustments->getRule($kind, $id);
        if ($id !== null && $rule === null) {
            Message::error(ucfirst($kindLabel) . ' rule not found.');
            return new RedirectResponse('/stockflow/' . $kind);
        }
        $new = $rule === null;
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'save_adjustment_rule') {
                Message::error('Unknown ' . $kindLabel . ' form submission.');
            } else {
                try {
                    $this->adjustments->saveRule($kind, $request->request->all(), $id, $productIds);
                    Message::info(ucfirst($kindLabel) . ' configuration saved.');
                    return new RedirectResponse('/stockflow/' . $kind);
                } catch (InvalidArgumentException $exception) {
                    Message::error($exception->getMessage());
                    $rule = array_merge($rule ?? [], $request->request->all());
                }
            }
        }
        return $this->page('adjustment-rule-form.twig', [
            'page_title' => ($new ? 'Create ' : 'Edit ') . $kindLabel,
            'adjustment_kind' => $kind,
            'adjustment_label' => ucfirst($kindLabel),
            'adjustment_rule' => $rule ?? [],
            'is_new' => $new,
            'products' => $products,
            'form_html' => $this->forms->adjustmentRule($request, $kind, $rule ?? [], $products, $new),
        ]);
    }

    public function shippingMethods(Request $request, string $route_name, array $options): Response
    {
        return $this->page('shipping-methods.twig', [
            'page_title' => 'Shipping methods',
            'shipping_methods' => $this->shippingMethods->getMethods(),
            'currency' => $this->stockflow->getStoreSettings()['currency'],
        ]);
    }

    public function shippingMethodCreate(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') === 'create_shipping_method'
                && $this->process(
                    fn () => $this->shippingMethods->saveMethod($request->request->all()),
                    'Shipping method created.'
                )) {
                return new RedirectResponse('/stockflow/shipping');
            }
            if ($request->request->get('_action') !== 'create_shipping_method') {
                Message::error('Unknown shipping method form submission.');
            }
        }
        return $this->page('shipping-method-form.twig', [
            'page_title' => 'Create shipping method',
            'form_title' => 'Shipping details and rules',
            'is_new' => true,
            'form_html' => $this->forms->shippingMethod($request, [], true),
        ]);
    }

    public function shippingMethodEdit(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('id');
        if (!is_string($id) || $id === '') {
            Message::error('Select a valid shipping method to edit.');
            return new RedirectResponse('/stockflow/shipping');
        }
        $method = $this->shippingMethods->getMethod($id);
        if ($method === null) {
            Message::error('Shipping method not found.');
            return new RedirectResponse('/stockflow/shipping');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'update_shipping_method') {
                Message::error('Unknown shipping method form submission.');
            } elseif ($this->process(
                fn () => $this->shippingMethods->saveMethod($request->request->all(), $id),
                'Shipping method updated.'
            )) {
                return new RedirectResponse('/stockflow/shipping');
            }
            $method = $this->shippingMethods->getMethod($id) ?? $method;
        }
        return $this->page('shipping-method-form.twig', [
            'page_title' => 'Edit shipping method',
            'form_title' => 'Shipping details and rules',
            'is_new' => false,
            'form_html' => $this->forms->shippingMethod($request, $method),
        ]);
    }

    public function paymentMethods(Request $request, string $route_name, array $options): Response
    {
        return $this->page('payment-methods.twig', [
            'page_title' => 'Payment methods',
            'payment_methods' => $this->paymentMethods->getMethods(),
            'encryption_key_configured' => (bool) getenv('PAYMENT_CONFIG_ENCRYPTION_KEY'),
        ]);
    }

    public function paymentMethodConfigure(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get('id');
        if (!is_string($id) || $id === '') {
            Message::error('Select a valid payment method to configure.');
            return new RedirectResponse('/stockflow/payment-methods');
        }
        $method = $this->paymentMethods->getMethod($id);
        if ($method === null) {
            Message::error('Payment method not found in payments.yml.');
            return new RedirectResponse('/stockflow/payment-methods');
        }
        if ($request->isMethod('POST')) {
            if ($request->request->get('_action') !== 'save_payment_method') {
                Message::error('Unknown payment method form submission.');
            } else {
                try {
                    $this->paymentMethods->saveMethod($id, $request->request->all());
                    Message::info('Payment method configuration saved.');
                    return new RedirectResponse('/stockflow/payment-methods');
                } catch (InvalidArgumentException $exception) {
                    Message::error($exception->getMessage());
                } catch (\RuntimeException $exception) {
                    getAppContainer()->get('logger')->error('Payment method configuration could not be saved', [
                        'payment_method' => $id,
                        'error' => $exception->getMessage(),
                    ]);
                    Message::error($exception->getMessage());
                }
            }
            foreach ($method['settings'] as $setting) {
                if (!empty($setting['secret'])) {
                    $request->request->remove($setting['name']);
                }
            }
            $method = $this->paymentMethods->getMethod($id) ?? $method;
        }
        return $this->page('payment-method-configure.twig', [
            'page_title' => 'Configure ' . $method['label'],
            'payment_method' => $method,
            'encryption_key_configured' => (bool) getenv('PAYMENT_CONFIG_ENCRYPTION_KEY'),
            'form_html' => $this->forms->paymentMethod($request, $method),
        ]);
    }

    private function process(callable $operation, string $successMessage): bool
    {
        try {
            $operation();
            Message::info($successMessage);
            return true;
        } catch (InvalidArgumentException $exception) {
            Message::error($exception->getMessage());
            return false;
        }
    }

    private function handleProductSubmission(Request $request): bool
    {
        $data = $request->request->all();
        return match ($request->request->get('_action')) {
            'create_product' => $this->process(fn () => $this->stockflow->createProduct($data), 'Product and its first variant were created.'),
            'create_variant' => $this->process(fn () => $this->stockflow->createVariant($data), 'Product variant created.'),
            'create_category' => $this->process(fn () => $this->stockflow->createCategory($data), 'Product category created.'),
            default => $this->unknownProductSubmission(),
        };
    }

    private function unknownProductSubmission(): bool
    {
        Message::error('Unknown product form submission.');
        return false;
    }

    private function queryFilter(Request $request, string $name): string
    {
        $value = $request->query->get($name, '');
        if (!is_string($value)) {
            Message::error('Invalid value supplied for order filter: ' . $name . '.');
            return '';
        }
        return $value;
    }

    private function queryId(Request $request, string $name): ?int
    {
        $id = filter_var($request->query->get($name), FILTER_VALIDATE_INT);
        return $id !== false && $id > 0 ? $id : null;
    }

    private function customerUsername(string $email): string
    {
        $localPart = strstr($email, '@', true);
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', $localPart ?: 'customer'));
        $base = trim($base, '.-_') ?: 'customer';
        $username = $base;
        $suffix = 1;
        while (User::loadByUsername($username, $this->database) !== null) {
            $username = $base . '-' . $suffix++;
        }
        return $username;
    }

    private function paymentLinkSignature(int $orderId, int $expires): string
    {
        $secret = getenv('STOCKFLOW_PAYMENT_LINK_SECRET')
            ?: ($_ENV['STOCKFLOW_PAYMENT_LINK_SECRET'] ?? getenv('CSRF_TOKEN_SECRET') ?: ($_ENV['CSRF_TOKEN_SECRET'] ?? ''));
        if (!is_string($secret) || trim($secret) === '') {
            throw new \RuntimeException('Configure STOCKFLOW_PAYMENT_LINK_SECRET or CSRF_TOKEN_SECRET before sending payment links.');
        }
        return hash_hmac('sha256', $orderId . '|' . $expires, $secret);
    }

    private function notifySupplier(array $purchaseOrder, string $event, ?int $quantityReceived = null): void
    {
        $email = trim((string) ($purchaseOrder['supplier_email'] ?? ''));
        if ($email === '') {
            getAppContainer()->get('logger')->warning('Purchase order saved without supplier notification: no supplier email address', [
                'purchase_order_id' => $purchaseOrder['id'],
                'event' => $event,
            ]);
            Message::error('Purchase order was saved, but the supplier has no email address for notification.');
            return;
        }

        $subjects = [
            'created' => 'New purchase order ' . $purchaseOrder['order_number'],
            'updated' => 'Updated purchase order ' . $purchaseOrder['order_number'],
            'received' => 'Stock received for purchase order ' . $purchaseOrder['order_number'],
        ];
        try {
            $content = $this->renderTwig('@stockflow/purchase-order-email.twig', [
                'purchase_order' => $purchaseOrder,
                'event' => $event,
                'quantity_received' => $quantityReceived,
                'store_name' => $this->stockflow->getStoreSettings()['store_name'],
            ]);
            $mailManager = getAppContainer()->get('mail.manager');
            if (!$mailManager->sendHtml($email, $subjects[$event], $content->getContent())) {
                throw new \RuntimeException('Mail manager reported that supplier notification delivery failed.');
            }
        } catch (\Throwable $exception) {
            getAppContainer()->get('logger')->warning('Purchase order was saved, but supplier notification failed', [
                'purchase_order_id' => $purchaseOrder['id'],
                'supplier_email' => $email,
                'event' => $event,
                'exception' => $exception->getMessage(),
            ]);
            Message::error('Purchase order was saved, but the supplier notification could not be sent.');
        }
    }

    private function page(string $template, array $data): Response
    {
        return $this->renderTwig('@stockflow/' . $template, $data + [
            'messages' => Message::send(),
            'store_name' => $this->stockflow->getStoreSettings()['store_name'],
            'page_title' => 'Stockflow',
        ]);
    }
}
