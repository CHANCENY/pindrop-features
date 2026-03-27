<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Payments;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Plugin\PluginManager;

class PaymentManager
{
    protected array $gateways;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function __construct()
    {
        $container = \getAppContainer();

        /**@var PluginManager $pluginManager **/
        $pluginManager = $container->get('plugin.manager');

        $paymentsGateways = $pluginManager->getPluginsYamlContent('payment.gateway');
        $paymentsGateways = array_values($paymentsGateways);
        $this->gateways = [];

        foreach ($paymentsGateways as $gateways) {
            foreach ($gateways as $gateway) {
                if (isset($gateway['class']) && class_exists($gateway['class']) && isset($gateway['settings'])) {

                    if (!empty($gateway['settings']['id']) && !empty($gateway['settings']['name']) && !empty($gateway['settings']['status'])) {
                        $class = $gateway['class'];
                        if (is_subclass_of($class, PaymentGatewayInterface::class)) {
                            $this->gateways[$gateway['settings']['id']] = new $class($container, $gateway);
                        }
                    }

                }
            }
        }
    }

    public function getGateways(): array {
        return $this->gateways;
    }

    public function getGateway(string $id): ?PaymentGatewayInterface
    {
        return $this->gateways[$id] ?? null;
    }
}