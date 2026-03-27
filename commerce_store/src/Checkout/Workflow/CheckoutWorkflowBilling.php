<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Checkout\Workflow;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\admin\src\Address\AddressFormatter;
use Simp\Pindrop\Modules\commerce_store\src\Order\Order;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderDraft;
use Simp\Pindrop\Modules\commerce_store\src\Order\OrderItem;
use Simp\Pindrop\Templating\TwigEngine;
use Symfony\Component\HttpFoundation\Request;

class CheckoutWorkflowBilling implements CheckoutWorkflowInterface
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

        /**@var OrderItem $orderItem **/
        $orderItem = $this->container->get('commerce_store.order_manager')->orderItem();
        $orderItems = $orderItem->getOrderItems($this->orderId);
        $addressFormatter = new AddressFormatter($request->request->get('billing_countryCode', 'US'));
        $order = $this->container->get('commerce_store.order_manager')->order()->getOrder($this->orderId);
        $customer = $this->container->get('commerce_store.order_manager')->customer()->getCustomer($order['customer_id']);

        $addressData = [
            'code' => $request->request->get('billing_countryCode', 'US'),
        ];
        if ($customer) {
            $addressData['address1'] = $customer['billing_address_1'];
            $addressData['first_name'] = $customer['first_name'];
            $addressData['last_name'] = $customer['last_name'];
            $addressData['city'] = $customer['billing_city'];
            $addressData['state'] = $customer['billing_state'];
            $addressData['postalcode'] = $customer['billing_postcode'];
            $addressData['country'] = $customer['billing_country'];
            $addressData['county'] = $customer['billing_county'] ?? "";
        }
        return $twig->render('@commerce_store/workflow/checkout_billing.twig', [
            'items' => $orderItems,
                'addressFormatter' => $addressFormatter->getAddressTemplate('billing', $addressData),
            ]
        );
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function processCheckoutStepFormFields(Request $request): OrderDraft
    {
        $order = $this->container->get('commerce_store.order_manager')->order()->getOrder($this->orderId);
        if (!empty($order['customer_id']) && $request->query->get('step') === 'billing') {

            $updateData = [
              'billing_address_1' => $request->request->get('billing_addressLine1', ''),
                'billing_address_2' => $request->request->get('billing_addressLine2', ''),
                'billing_city'    => $request->request->get('billing_locality', ''),
                'billing_state'    => $request->request->get('billing_administrativeArea', ''),
                'billing_postcode' => $request->request->get('billing_postalCode', ''),
                'billing_country'  => $request->request->get('billing_countryCode', ''),
                'first_name'       => $request->request->get('billing_givenName', ""),
                'last_name'        => $request->request->get('billing_familyName', ''),
            ];

            $customer =  $this->container->get('commerce_store.order_manager')->customer()->getCustomer($order['customer_id']);

            $data = array_merge($customer,$updateData);


            unset($data['created_at']);
            unset($data['updated_at']);
            unset($data['id']);
            unset($data['total_orders']);
            unset($data['total_spent']);
            unset($data['last_order_at']);

            $this->container->get('commerce_store.order_manager')->customer()->updateCustomer($order['customer_id'], $data);

            $orderDraft = new OrderDraft();
            $orderDraft->setMessage("Billing updated successfully");
            return $orderDraft;
        }
        return new OrderDraft();
    }
}