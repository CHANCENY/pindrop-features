<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Payments;

use DI\Container;
use Simp\Pindrop\Form\FormInterface;
use Simp\Pindrop\Form\FormStateInterface;

interface PaymentGatewayInterface
{
    public string $gatewayId {
        get;
        set;
    }
    public string $gatewayName {
        get;
        set;
    }

    public string $gatewayDescription {
        get;
        set;
    }

    public array $gatewaySettings {
        get;
        set;
    }

    public Container $container {
        get;
        set;
    }

    public function __construct(Container $container, array $gatewaySettings);

    public function getPaymentForm(array $form, FormStateInterface $formState, array $options = []): array;

    public function processForm(array $form, FormStateInterface &$formState, array $order, Payment &$payment);
}