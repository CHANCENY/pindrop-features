<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Checkout\Workflow;

use DI\Container;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderDraft;
use Symfony\Component\HttpFoundation\Request;

interface CheckoutWorkflowInterface
{
    public function __construct(Container $container, int $orderId, string $step);

    public function buildCheckoutStepFormFields(Request $request): string;

    public function processCheckoutStepFormFields(Request $request): OrderDraft;

}