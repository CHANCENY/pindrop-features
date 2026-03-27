<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Checkout\Workflow;

use DI\Container;
use Simp\Pindrop\Modules\commerce_store\src\Order\Order;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderDraft;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderItem;
use Simp\Pindrop\Templating\TwigEngine;
use Symfony\Component\HttpFoundation\Request;

class CheckoutWorkflowReview implements CheckoutWorkflowInterface
{

    protected Container $container;
    protected int $orderId;
    protected string $step;


    public function __construct(Container $container, int $orderId, string $step)
    {
        $this->container = $container;
        $this->orderId = $orderId;
        $this->step = $step;
    }

    public function buildCheckoutStepFormFields(Request $request): string
    {
        /**@var TwigEngine $twig **/
        $twig = $this->container->get('twig');

        /**@var OrderItem $orderItem **/
        $orderItem = $this->container->get('commerce_store.order_manager')->orderItem();
        $orderItems = $orderItem->getOrderItems($this->orderId);
        return $twig->render('@commerce_store/workflow/checkout_review.twig', ['items' => $orderItems]);
    }

    public function processCheckoutStepFormFields(Request $request): OrderDraft
    {
        return new OrderDraft();
    }
}