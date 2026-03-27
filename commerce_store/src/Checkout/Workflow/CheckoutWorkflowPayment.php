<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Checkout\Workflow;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Form\FormBuilder;
use Simp\Pindrop\Form\FormState;
use Simp\Pindrop\Modules\admin\src\Address\AddressFormatter;
use Simp\Pindrop\Modules\commerce_store\src\Order\Order;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderDraft;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderItem;
use Simp\Pindrop\Modules\commerce_store\src\Payments\Gateway\QuotePaymentGateway;
use Simp\Pindrop\Modules\commerce_store\src\Payments\Payment;
use Simp\Pindrop\Modules\commerce_store\src\Payments\PaymentGatewayInterface;
use Simp\Pindrop\Modules\commerce_store\src\Payments\PaymentManager;
use Simp\Pindrop\Templating\TwigEngine;
use Symfony\Component\HttpFoundation\Request;

class CheckoutWorkflowPayment implements CheckoutWorkflowInterface
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

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function buildCheckoutStepFormFields(Request $request): string
    {
        /**@var TwigEngine $twig **/
        $twig = $this->container->get('twig');
        $order = $this->container->get('commerce_store.order_manager')->order()->getOrder($this->orderId);

        /**@var PaymentManager $paymentManager **/
        $paymentManager = $this->container->get('commerce_store.payment.manager');
        $options = $paymentManager->getGateways();

        /**@var QuotePaymentGateway $pickedFirstDefault **/
        $pickedFirstDefault = $options[key($options)];

        $formFields = [];
        $formState = new FormState();
        $form = $pickedFirstDefault->getPaymentForm($formFields, $formState);
        $formState->buildFormState($form, $request);
        $formBuilder = new FormBuilder();
        $form = $formBuilder->buildFormRender($form, $formState, $request);

        if ($request->isMethod("POST")) {

        }

        return $twig->render('@commerce_store/workflow/checkout_payment.twig', [
            'payment_gateways' => $options,
            'selected_gateway' => $pickedFirstDefault->gatewayId,
            'default_gateway'  => $form
        ]);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function processCheckoutStepFormFields(Request $request): OrderDraft
    {
        if ($request->query->get('step') === 'payment') {
            $order = $this->container->get('commerce_store.order_manager')->order()->getOrder($this->orderId);
            /**@var PaymentManager $paymentManager **/
            $paymentManager = $this->container->get('commerce_store.payment.manager');
            $paymentGateway = $paymentManager->getGateway($request->request->get('gateway_id'));
            $formFields = [];
            $formState = new FormState();
            $orderDraft = new OrderDraft();
            if ($paymentGateway instanceof PaymentGatewayInterface) {
                $form = $paymentGateway->getPaymentForm($formFields, $formState);
                $formState->buildFormState($form, $request);
                $payment = new Payment($this->container->get('database'), $this->container->get('logger'));
                $paymentGateway->processForm($form,$formState,$order,$payment);

                $messages = $formState->getMessages();
                $errors = $formState->getErrors();

                if (!empty($messages)) {
                    $messages = reset($messages);
                    if (!empty($messages)) {
                        foreach ($messages ?? [] as $message) {
                            $orderDraft->setMessage($message);
                        }
                    }
                }

                $errors = reset($errors);
                if (!empty($errors)) {
                    foreach ($errors as $error) {
                        $orderDraft->setMessage($error,2);
                    }
                }
                return $orderDraft;
            }

        }
        return new OrderDraft();

    }
}