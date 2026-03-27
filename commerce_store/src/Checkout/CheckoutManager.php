<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Checkout;

use DI\Container;
use Simp\Pindrop\Modules\commerce_store\src\Checkout\Workflow\CheckoutWorkflowInterface;
use Simp\Pindrop\Plugin\PluginManager;
use function DI\value;

class CheckoutManager
{
    protected array $checkoutSteps = [];
    protected array $checkoutStepWorkflow = [];
    public function __construct()
    {
        $container = \getAppContainer();
        $this->checkoutSteps = [];
        $this->checkoutStepWorkflow = [];

        /**@var PluginManager $pluginManager **/
        $pluginManager = $container->get('plugin.manager');

        $checkoutSteps = $pluginManager->getPluginsYamlContent('checkout.steps');
        $checkoutSteps = array_values($checkoutSteps);
        foreach ($checkoutSteps as $step) {
            foreach ($step as $k=>$s) {
                if (!empty($s['status']) && $s['status'] == 'open') {
                    $this->checkoutSteps[$k] = $s;
                }
            }
        }

        uasort($this->checkoutSteps, function ($a, $b) {
            return $a['weight'] <=> $b['weight'];
        });

        $checkoutStepWorkflow = $pluginManager->getPluginsYamlContent('checkout.workflow');
        $checkoutStepWorkflow = array_values($checkoutStepWorkflow);
        foreach ($checkoutStepWorkflow as $step) {
            foreach ($step as $k=>$s) {
                $this->checkoutStepWorkflow[$k] = $s['handlers'] ?? [];
            }
        }
    }

    public function getCheckoutSteps(): array {
        return $this->checkoutSteps;
    }

    public function getCheckoutStepWorkflow(): array {
        return $this->checkoutStepWorkflow;
    }

    public function getStepWorkFlows(string $step)
    {
        return $this->checkoutStepWorkflow[$step] ?? [];
    }

    /**
     * @throws \ReflectionException
     */
    public function constructWorkFlowHandlerObject(string $handler, Container $container, int $id, string $step)
    {
        if (class_exists($handler) && key(class_implements($handler)) == CheckoutWorkflowInterface::class) {
            $reflection = new \ReflectionClass($handler);
            return $reflection->newInstance($container, $id, $step);
        }
        return null;
    }
}