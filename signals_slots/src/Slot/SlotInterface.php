<?php

namespace Simp\Pindrop\Modules\signals_slots\src\Slot;

/**
 * Every slot class registered in a plugin's slots.yml must implement this.
 *
 * Example slots.yml entry:
 *
 *   my_plugin.send_welcome_email:
 *     name: "Send Welcome Email"
 *     description: "Sends a welcome email when a user registers."
 *     class: Simp\Pindrop\Modules\my_plugin\src\Slot\SendWelcomeEmailSlot
 *     plugin: my_plugin
 *
 * Example implementation:
 *
 *   class SendWelcomeEmailSlot implements SlotInterface
 *   {
 *       public function handle(array $payload): void
 *       {
 *           // $payload is whatever was passed to SignalBus::emit()
 *           $mailer = getAppContainer()->get('mailer');
 *           $mailer->send($payload['email'], 'Welcome!', '...');
 *       }
 *   }
 */
interface SlotInterface
{
    /**
     * Called by SignalBus when the connected signal is emitted.
     *
     * @param array $payload  Arbitrary data passed by the emitter.
     *                        Your slot must be defensive — keys are
     *                        not guaranteed if the signal is emitted
     *                        by another plugin.
     */
    public function handle(array $payload): void;
}
