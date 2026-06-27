<?php

namespace Simp\Pindrop\Modules\core_signals\src\Slot;

use Simp\Pindrop\Mail\MailManager;
use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Sends an email via the framework MailManager.
 *
 * Required payload keys:
 *   'to'      string  recipient address
 *   'subject' string  email subject
 *   'body'    string  email body
 *
 * Optional payload keys:
 *   'html'    bool    send as HTML (default: false)
 *   'cc'      string|string[]
 *   'bcc'     string|string[]
 */
class SendEmailSlot implements SlotInterface
{
    public function handle(array $payload): void
    {
        $to      = $payload['to']      ?? null;
        $subject = $payload['subject'] ?? null;
        $body    = $payload['body']    ?? null;

        if (!$to || !$subject || !$body) {
            \getAppContainer()->get('logger')->warning(
                'core.slot.send_email: missing required payload keys (to, subject, body).',
                ['payload' => $payload]
            );
            return;
        }

        try {
            /** @var MailManager $mailer */
            $mailer = \getAppContainer()->get('mail.manager');

            $options = array_filter([
                'html' => $payload['html'] ?? false,
                'cc'   => $payload['cc']  ?? null,
                'bcc'  => $payload['bcc'] ?? null,
            ], fn($v) => $v !== null);

            $mailer->sendHtml($to, $subject, $body);
        } catch (\Throwable $e) {
            \getAppContainer()->get('logger')->error(
                'core.slot.send_email failed: ' . $e->getMessage(),
                ['to' => $to, 'subject' => $subject]
            );
        }
    }
}
