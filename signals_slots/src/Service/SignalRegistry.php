<?php

namespace Simp\Pindrop\Modules\signals_slots\src\Service;

use Simp\Pindrop\Plugin\PluginManager;

/**
 * Loads every plugin's signals.yml and slots.yml at boot and exposes
 * them as flat associative arrays keyed by their declared ID.
 *
 * signals.yml (in any plugin):
 *
 *   my_plugin.user_registered:
 *     name: "User Registered"
 *     description: "Emitted after a new user successfully registers."
 *     plugin: my_plugin
 *
 * slots.yml (in any plugin):
 *
 *   my_plugin.send_welcome_email:
 *     name: "Send Welcome Email"
 *     description: "Sends a welcome email to the new user."
 *     class: Simp\Pindrop\Modules\my_plugin\src\Slot\SendWelcomeEmailSlot
 *     plugin: my_plugin
 */
class SignalRegistry
{
    /** @var array<string, array{name:string, description:string, plugin:string}> */
    protected array $signals = [];

    /** @var array<string, array{name:string, description:string, class:string, plugin:string}> */
    protected array $slots = [];

    public function __construct(PluginManager $pluginManager)
    {
        // Collect signals
        foreach ($pluginManager->getPluginsYamlContent('signals') as $plugin) {
            foreach ($plugin as $key => $def) {
                if (!empty($def['name'])) {
                    $this->signals[$key] = $def;
                }
            }
        }

        // Collect slots — only those with a valid class implementing SlotInterface
        foreach ($pluginManager->getPluginsYamlContent('slots') as $plugin) {
            foreach ($plugin as $id => $def) {
                if (
                    !empty($def['name']) &&
                    !empty($def['class']) &&
                    class_exists($def['class']) &&
                    in_array(
                        \Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface::class,
                        class_implements($def['class']) ?: [],
                        true
                    )
                ) {
                    $this->slots[$id] = $def;
                }
            }
        }
    }

    /** All declared signals, keyed by signal key. */
    public function getSignals(): array
    {
        return $this->signals;
    }

    /** All declared slots, keyed by slot id. */
    public function getSlots(): array
    {
        return $this->slots;
    }

    public function getSignal(string $key): ?array
    {
        return $this->signals[$key] ?? null;
    }

    public function getSlot(string $id): ?array
    {
        return $this->slots[$id] ?? null;
    }

    public function signalExists(string $key): bool
    {
        return isset($this->signals[$key]);
    }

    public function slotExists(string $id): bool
    {
        return isset($this->slots[$id]);
    }
}
