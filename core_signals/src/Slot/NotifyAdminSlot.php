<?php

namespace Simp\Pindrop\Modules\core_signals\src\Slot;

use Simp\Pindrop\Mail\MailManager;
use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;
use Simp\Pindrop\Services\EnvServiceProvider;

/**
 * Emails the admin address (ADMIN_EMAIL env var) with a plain-text
 * summary of the signal and its payload.
 *
 * Works with any signal — no required payload keys.
 * Does nothing gracefully if ADMIN_EMAIL is not configured.
 */
class NotifyAdminSlot implements SlotInterface
{
    public function handle(array $payload): void
    {
        $adminEmail = EnvServiceProvider::getInstance()->get('ADMIN_EMAIL', '');

        if (empty($adminEmail)) {
            return;
        }

        $signal  = $payload['_signal'] ?? 'unknown.signal';
        $appName = EnvServiceProvider::getInstance()->get('APP_NAME', 'Pindrop');

        // Build a readable summary
        $lines = ["Signal: {$signal}", ""];
        foreach ($payload as $key => $value) {
            if ($key === '_signal') {
                continue;
            }
            $printable = is_scalar($value) ? (string) $value : json_encode($value, JSON_PRETTY_PRINT);
            $lines[]   = "  {$key}: <pre>{$printable}</pre>";
        }
        $lines[] = "";
        $lines[] = "-- {$appName}";

        $body    = implode(PHP_EOL, $lines);
        $subject = "[{$appName}] Signal: {$signal}";

        try {
            /** @var MailManager $mailer */
            $mailer = \getAppContainer()->get('mail.manager');
            $mailer->sendHtml($adminEmail, $subject, $body);
        } catch (\Throwable $e) {
            \getAppContainer()->get('logger')->error(
                'core.slot.notify_admin failed: ' . $e->getMessage(),
                ['signal' => $signal, 'admin_email' => $adminEmail]
            );
        }
    }
}
