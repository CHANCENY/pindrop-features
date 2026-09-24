<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Plugin\Cron;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionSubscriberInterface;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\Schedule;
use Simp\Pindrop\Modules\mailbox\src\Plugin\MailboxSettings;
use Simp\Pindrop\Modules\mailbox\src\Services\MailboxManager;
use Simp\Pindrop\Modules\mailbox\src\Services\MailboxSignals;

/**
 * Polls the account's watched folder (default INBOX) and caches new mail.
 * Wire it up: Admin > Cron > (a job) > add a Schedule with
 * subscriber = mailbox.sync.subscriber and whatever interval you want.
 *
 * Other folders are NOT synced here — they load (and cache) on demand the
 * first time someone opens them in the UI. This subscriber exists so the
 * watched folder's unread count and mailbox.message.received signal stay
 * current even when nobody has the mailbox open.
 */
class MailboxSyncSubscriber implements CronDefinitionSubscriberInterface
{
    public function __construct(protected DatabaseService $databaseService)
    {
    }

    public function name(): string
    {
        return 'Mailbox sync';
    }

    public function id(): string
    {
        return 'mailbox.sync.subscriber';
    }

    public function runSchedules(array $schedules): string
    {
        $summary = '';
        foreach ($schedules as $schedule) {
            $summary .= $this->runOne($schedule);
        }
        return $summary;
    }

    private function runOne(Schedule $schedule): string
    {
        $schedule->addLog('Mailbox sync starting', 'start');
        try {
            if (!MailboxSettings::isConfigured()) {
                $schedule->addLog('No mailbox account configured; skipping.', 'info');
                $schedule->finished();
                return "skipped (unconfigured)\n";
            }
            $account = MailboxSettings::resolveAccount();
            $manager = MailboxManager::fromAccount($account);
            $result = $manager->syncFolder($account['watchFolder']);
            $newCount = count($result['newUids'] ?? []);
            $schedule->addLog("Synced {$account['watchFolder']}: {$newCount} new message(s).", 'ok');

            foreach ($result['newUids'] ?? [] as $uid) {
                try {
                    $row = $this->databaseService->table('mailbox_messages')
                        ->where('folder', '=', $account['watchFolder'])
                        ->where('uid', '=', $uid)
                        ->first();
                    if ($row !== null) {
                        MailboxSignals::messageReceived($account['watchFolder'], [
                            'uid' => (int) $row['uid'],
                            'subject' => $row['subject'],
                            'from' => ['name' => $row['from_name'], 'email' => $row['from_email']],
                            'date' => $row['message_date'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    $schedule->addLog('Could not emit signal for UID ' . $uid . ': ' . $e->getMessage(), 'warn');
                }
            }

            $schedule->finished();
            return "ok ({$newCount} new)\n";
        } catch (\Throwable $e) {
            $schedule->addLog('Mailbox sync failed: ' . $e->getMessage(), 'error');
            $schedule->finished();
            MailboxSignals::syncFailed($e->getMessage());
            return 'error: ' . $e->getMessage() . "\n";
        }
    }
}
