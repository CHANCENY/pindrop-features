<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Services;

/**
 * AES-256-GCM envelope encryption for the IMAP password stored in settings.
 *
 * The CMS core has no secrets-encryption helper (confirmed by inspecting
 * pindrop/ for anything crypto-related), so this plugin keys its own from a
 * dedicated MAILBOX_ENCRYPTION_KEY env var — deliberately separate from
 * CSRF_TOKEN_SECRET, since that one is meant for short-lived, low-stakes
 * tokens, not for protecting a password at rest indefinitely.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $key = self::key();
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 12 + 16) {
            throw new \RuntimeException('Stored credential is corrupt or was encrypted with a different key.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Stored credential could not be decrypted (wrong MAILBOX_ENCRYPTION_KEY, or it changed).');
        }
        return $plaintext;
    }

    public static function isConfigured(): bool
    {
        return trim((string) (getenv('MAILBOX_ENCRYPTION_KEY') ?: ($_ENV['MAILBOX_ENCRYPTION_KEY'] ?? ''))) !== '';
    }

    public static function generateKeySuggestion(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function key(): string
    {
        $raw = trim((string) (getenv('MAILBOX_ENCRYPTION_KEY') ?: ($_ENV['MAILBOX_ENCRYPTION_KEY'] ?? '')));
        if ($raw === '') {
            throw new \RuntimeException('MAILBOX_ENCRYPTION_KEY is not set in .env. The mailbox plugin refuses to store or read credentials without it.');
        }
        // Accept either a base64-encoded 32-byte key (recommended, as generated
        // above) or fall back to deriving one from arbitrary text via HKDF so a
        // hand-typed passphrase still works.
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }
        return hash_hkdf('sha256', $raw, 32, 'mailbox-plugin-credential-key');
    }
}
