<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Payments\Gateway;

use DI\Container;
use Simp\Pindrop\Form\FormStateInterface;
use Simp\Pindrop\Modules\commerce_store\src\Payments\Payment;
use Simp\Pindrop\Modules\commerce_store\src\Payments\PaymentGatewayInterface;

class PurchaseOrderGateway implements PaymentGatewayInterface
{

    public string $gatewayId {
        get {
            return $this->gatewayId;
        }
        set {
            $this->gatewayId = $value;
        }
    }
    public string $gatewayName {
        get {
            return $this->gatewayName;
        }
        set {
            $this->gatewayName = $value;
        }
    }
    public string $gatewayDescription {
        get {
            return $this->gatewayDescription;
        }
        set {
            $this->gatewayDescription = $value;
        }
    }
    public array $gatewaySettings {
        get {
            return $this->gatewaySettings;
        }
        set {
            $this->gatewaySettings = $value;
        }
    }
    public \DI\Container $container {
        get {
            return $this->container;
        }
        set {
            $this->container = $value;
        }
    }

    public function __construct(Container $container, array $gatewaySettings)
    {
        $this->container = $container;
        $this->gatewaySettings = $gatewaySettings;
        $this->gatewayId = $gatewaySettings['settings']['id'] ?? throw new \Exception("Payment Gateway settings id not found");
        $this->gatewayName = $gatewaySettings['settings']['name'] ?? throw new \Exception("Payment Gateway settings name not found");
        $this->gatewayDescription = $gatewaySettings['settings']['description'] ?? "";
    }

    public function getPaymentForm(array $form, FormStateInterface $formState, array $options = []): array
    {
        $form['purchase_order_wrapper'] = [
            '#fieldset' => [
                'legend' => 'Make Purchase order payment'
            ],
            'fields' => [
                'po_number' => [
                    'type' => 'text',
                    'title' => 'PO Number',
                    'value' => $options['po_number'] ?? '',
                    'placeholder' => 'enter your po number',
                ],
            ]
        ];
        return $form;
    }

    public function processForm(array $form, FormStateInterface &$formState, array $order, Payment &$payment)
    {
        if (!$formState->getRequest()->isMethod('POST')) return;

        // Get form data
        $poNumber = $formState->getValue('po_number');

        if (empty($poNumber)) {
            $formState->setError( 'Quote message is required');
            return;
        }

        try {
            // Create payment record for quote request
            $paymentData = [
                'order_id' => $order['id'],
                'payment_method' => 'purchase_order',
                'payment_gateway' => $this->gatewayId,
                'transaction_id' => 'PO_' . time() . '_' . $order['id'],
                'amount' => $order['total_amount'],
                'currency' => $order['currency'],
                'status' => 'processing',
                'payment_type' => 'authorization',
                'gateway_response' => json_encode([
                    'po_number' => $poNumber,
                    'purchase_order' => 'requested',
                    'requested_at' => date('Y-m-d H:i:s'),
                    'order_details' => [
                        'order_number' => $order['order_number'],
                        'customer_email' => $order['customer']['email'] ?? null,
                        'total_amount' => $order['total_amount']
                    ]
                ]),
                'notes' => 'Purchase order submitted by customer'
            ];

            // Create payment record
            $paymentId = $payment->createPayment($paymentData);

            // Log the quote request
            $this->container->get('logger')->info('Purchase order payment request processed', [
                'payment_id' => $paymentId,
                'order_id' => $order['id'],
                'po_number' => $poNumber,
                'amount' => $order['total_amount']
            ]);

            // Set success message
            $formState->setMessage("Purchase order payment processed");

        } catch (\Exception $e) {

            // Set error message
            $formState->setError('Failed to process purchase order request: ' . $e->getMessage());

            $this->container->get('logger')->error('Quote payment request failed', [
                'order_id' => $order['id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}