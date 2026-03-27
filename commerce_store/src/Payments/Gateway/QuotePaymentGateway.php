<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Payments\Gateway;

use DI\Container;
use Simp\Pindrop\Form\FormStateInterface;
use Simp\Pindrop\Modules\commerce_store\src\Payments\Payment;
use Simp\Pindrop\Modules\commerce_store\src\Payments\PaymentGatewayInterface;

class QuotePaymentGateway implements PaymentGatewayInterface
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

    /**
     * @throws \Exception
     */
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
        $form['quote_payment_wrapper'] = [
            '#fieldset' => [
                'legend' => 'Make Quote payment'
            ],
            'fields' => [
                'quote_payment' => [
                    'type' => 'textarea',
                    'title' => 'Quote Request Message',
                    'value' => $options['quote_payment'] ?? $formState->getValue('quote_payment') ?? "",
                    'placeholder' => 'Write your quote request message',
                    'rows' => 4,
                    'class' => 'form-control editor'
                ],
                'gateway_name' => [
                    'type' => 'hidden',
                    'title' => '',
                    'value' => $this->gatewayId
                ]
            ]
        ];
        return $form;
    }

    public function processForm(array $form, FormStateInterface &$formState, array $order, Payment &$payment)
    {
        if (!$formState->getRequest()->isMethod('POST')) return;

        // Get form data
        $quoteMessage = $formState->getValue('quote_payment');
        
        if (empty($quoteMessage)) {
            $formState->setError( 'Quote message is required');
            return;
        }

        try {
            // Create payment record for quote request
            $paymentData = [
                'order_id' => $order['id'],
                'payment_method' => 'quote_request',
                'payment_gateway' => $this->gatewayId,
                'transaction_id' => 'QUOTE_' . time() . '_' . $order['id'],
                'amount' => $order['total_amount'],
                'currency' => $order['currency'],
                'status' => 'pending',
                'payment_type' => 'authorization',
                'gateway_response' => json_encode([
                    'quote_message' => $quoteMessage,
                    'quote_status' => 'requested',
                    'requested_at' => date('Y-m-d H:i:s'),
                    'order_details' => [
                        'order_number' => $order['order_number'],
                        'customer_email' => $order['customer']['email'] ?? null,
                        'total_amount' => $order['total_amount']
                    ]
                ]),
                'notes' => 'Quote request submitted by customer'
            ];

            // Create payment record
            $paymentId = $payment->createPayment($paymentData);

            // Log the quote request
            $this->container->get('logger')->info('Quote payment request processed', [
                'payment_id' => $paymentId,
                'order_id' => $order['id'],
                'quote_message' => $quoteMessage,
                'amount' => $order['total_amount']
            ]);

            // Set success message
            $formState->setMessage("Quote request processed");

        } catch (\Exception $e) {

            // Set error message
            $formState->setError('Failed to process quote request: ' . $e->getMessage());
            
            $this->container->get('logger')->error('Quote payment request failed', [
                'order_id' => $order['id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}