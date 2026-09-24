<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Plugin;

use Simp\Pindrop\Modules\mailbox\src\Services\Crypto;
use Simp\Pindrop\Modules\mailbox\src\Services\MailboxRequestGuard;
use Simp\Pindrop\Settings\Setting;
use Simp\Pindrop\Settings\Settings;
use Simp\Pindrop\Settings\SettingsInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Account settings panel (Admin > Settings). Single account for v1.
 *
 * The password is never round-tripped to the browser: the form shows a
 * placeholder, and leaving it blank on save keeps the existing encrypted
 * value. A new value gets re-encrypted with Crypto before storage.
 */
class MailboxSettings implements SettingsInterface
{
    public const KEY = 'mailbox.account';

    public function settingKey(): string
    {
        return self::KEY;
    }

    public function formBuild(Request $request, ?Setting $setting): string
    {
        $v = $setting?->getValue() ?? [];
        return getAppContainer()->get('twig')->render('@mailbox/settings/form.html.twig', [
            'mb' => [
                'host' => (string) ($v['host'] ?? ''),
                'port' => (int) ($v['port'] ?? 993),
                'encryption' => (string) ($v['encryption'] ?? 'ssl'),
                'username' => (string) ($v['username'] ?? ''),
                'has_password' => !empty($v['password_enc']),
                'watch_folder' => (string) ($v['watch_folder'] ?? 'INBOX'),
                'sent_folder' => (string) ($v['sent_folder'] ?? ''),
                'drafts_folder' => (string) ($v['drafts_folder'] ?? ''),
                'trash_folder' => (string) ($v['trash_folder'] ?? ''),
                'junk_folder' => (string) ($v['junk_folder'] ?? ''),
                'messages_per_page' => (int) ($v['messages_per_page'] ?? 50),
                'signature' => (string) ($v['signature'] ?? ''),
            ],
            'crypto_configured' => Crypto::isConfigured(),
            'key_suggestion' => Crypto::isConfigured() ? null : Crypto::generateKeySuggestion(),
        ]);
    }

    public function savableValues(Request $request): array
    {
        try {
            MailboxRequestGuard::requireUser();
        } catch (\Throwable) {
            return self::stored(); // no permission: keep whatever is already stored
        }

        $in = $request->request;
        $existing = self::stored();

        $password = (string) $in->get('mb_password', '');
        $passwordEnc = $existing['password_enc'] ?? null;
        if ($password !== '') {
            if (!Crypto::isConfigured()) {
                // Can't store a new password safely; keep the old one and let the
                // account-test / sync surface the missing-key error clearly.
                $passwordEnc = $existing['password_enc'] ?? null;
            } else {
                $passwordEnc = Crypto::encrypt($password);
            }
        }

        return [
            'host' => trim((string) $in->get('mb_host', '')),
            'port' => max(1, min(65535, (int) $in->get('mb_port', 993))),
            'encryption' => in_array($in->get('mb_encryption'), ['ssl', 'tls', 'none'], true) ? $in->get('mb_encryption') : 'ssl',
            'username' => trim((string) $in->get('mb_username', '')),
            'password_enc' => $passwordEnc,
            'watch_folder' => trim((string) $in->get('mb_watch_folder', 'INBOX')) ?: 'INBOX',
            'sent_folder' => trim((string) $in->get('mb_sent_folder', '')),
            'drafts_folder' => trim((string) $in->get('mb_drafts_folder', '')),
            'trash_folder' => trim((string) $in->get('mb_trash_folder', '')),
            'junk_folder' => trim((string) $in->get('mb_junk_folder', '')),
            'messages_per_page' => max(10, min(200, (int) $in->get('mb_messages_per_page', 50))),
            'signature' => (string) $in->get('mb_signature', ''),
        ];
    }

    public static function stored(): array
    {
        try {
            $setting = getAppContainer()->get(Settings::class)->getSetting(self::KEY);
            return $setting ? $setting->getValue() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function isConfigured(): bool
    {
        $v = self::stored();
        return ($v['host'] ?? '') !== '' && ($v['username'] ?? '') !== '' && !empty($v['password_enc']);
    }

    /**
     * @return array{host:string,port:int,encryption:string,username:string,password:string,
     *   watchFolder:string,folderRoles:array<string,string>,messagesPerPage:int,signature:string}
     */
    public static function resolveAccount(): array
    {
        $v = self::stored();
        if (($v['host'] ?? '') === '' || ($v['username'] ?? '') === '' || empty($v['password_enc'])) {
            throw new \RuntimeException('No mailbox account is configured yet. Set one up under Admin > Settings.');
        }
        return [
            'host' => (string) $v['host'],
            'port' => (int) ($v['port'] ?? 993),
            'encryption' => (string) ($v['encryption'] ?? 'ssl'),
            'username' => (string) $v['username'],
            'password' => Crypto::decrypt((string) $v['password_enc']),
            'watchFolder' => (string) ($v['watch_folder'] ?? 'INBOX'),
            'folderRoles' => array_filter([
                'sent' => (string) ($v['sent_folder'] ?? ''),
                'drafts' => (string) ($v['drafts_folder'] ?? ''),
                'trash' => (string) ($v['trash_folder'] ?? ''),
                'junk' => (string) ($v['junk_folder'] ?? ''),
            ]),
            'messagesPerPage' => (int) ($v['messages_per_page'] ?? 50),
            'signature' => (string) ($v['signature'] ?? ''),
        ];
    }
}
