<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Shipment;

use Simp\Pindrop\Plugin\PluginManager;

class ShipmentMethodManager
{
    protected array $shippingMethods;
    public function __construct()
    {
        $container = \getAppContainer();
        $this->shippingMethods = [];

        /**@var PluginManager $pluginManager **/
        $pluginManager = $container->get('plugin.manager');

        $shippingMethods = $pluginManager->getPluginsYamlContent('shipping.method');
        $shippingMethods = array_values($shippingMethods);

        foreach ($shippingMethods as $shippingMethod) {
            foreach ($shippingMethod as $k=>$method) {
                if (!empty($method['status']) && $method['status'] == 'open') {
                    $this->shippingMethods[$k] = $method;
                }
            }
        }

    }

    public function getShippingMethods(): array {
        return $this->shippingMethods;
    }

    public function getShippingMethod(string $shippingMethodCode) {
        return $this->shippingMethods[$shippingMethodCode] ?? null;
    }
}