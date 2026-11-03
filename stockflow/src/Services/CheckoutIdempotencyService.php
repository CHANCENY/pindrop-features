<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Services;

use RuntimeException;
use Simp\Pindrop\Database\DatabaseService;

final class CheckoutIdempotencyService
{
    private const RETENTION_HOURS = 24;

    public function __construct(private readonly DatabaseService $database)
    {
    }

    public function claim(int $userId, string $key, string $requestHash): array
    {
        $keyHash = hash('sha256', $key);
        $now = new \DateTimeImmutable('now');
        $existing = $this->find($userId, $keyHash);
        $expired = $existing !== null
            && $existing['status'] === 'completed'
            && new \DateTimeImmutable((string) $existing['expires_at']) <= $now;
        $staleProcessing = $existing !== null
            && $existing['status'] === 'processing'
            && $existing['order_id'] === null
            && hash_equals((string) $existing['request_hash'], $requestHash)
            && new \DateTimeImmutable((string) $existing['updated_at']) <= $now->modify('-5 minutes');
        if ($expired || $staleProcessing) {
            $delete = $this->database->table('checkout_idempotency', 'stockflow')
                ->where('id', '=', (int) $existing['id']);
            if ($expired) {
                $delete->where('expires_at', '<=', $now->format('Y-m-d H:i:s'));
            } else {
                $delete->where('status', '=', 'processing')
                    ->whereNull('order_id')
                    ->where('updated_at', '<=', $now->modify('-5 minutes')->format('Y-m-d H:i:s'));
            }
            $delete->delete();
        }

        $expiresAt = $now->modify('+' . self::RETENTION_HOURS . ' hours');
        $claimToken = hash('sha256', random_bytes(32));
        $this->database->table('checkout_idempotency', 'stockflow')->insertIgnore([
            'user_id' => $userId,
            'key_hash' => $keyHash,
            'claim_token' => $claimToken,
            'request_hash' => $requestHash,
            'status' => 'processing',
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
        $existing = $this->find($userId, $keyHash);
        if ($existing === null) {
            throw new RuntimeException('The checkout idempotency request could not be loaded.');
        }
        if (hash_equals((string) $existing['claim_token'], $claimToken)) {
            return ['state' => 'acquired', 'key_hash' => $keyHash, 'claim_token' => $claimToken];
        }

        if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
            return ['state' => 'conflict'];
        }
        if ($existing['status'] === 'completed') {
            $body = json_decode((string) $existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body)) {
                throw new RuntimeException('The saved checkout response is invalid.');
            }
            return [
                'state' => 'replay',
                'status_code' => (int) $existing['response_status'],
                'body' => $body,
            ];
        }
        return [
            'state' => 'processing',
            'order_id' => $existing['order_id'] === null ? null : (int) $existing['order_id'],
        ];
    }

    public function attachOrder(int $userId, string $keyHash, string $claimToken, int $orderId): void
    {
        $updated = $this->database->table('checkout_idempotency', 'stockflow')
            ->where('user_id', '=', $userId)
            ->where('key_hash', '=', $keyHash)
            ->where('claim_token', '=', $claimToken)
            ->where('status', '=', 'processing')
            ->whereNull('order_id')
            ->update(['order_id' => $orderId]);
        if ($updated !== 1) {
            throw new RuntimeException('The checkout idempotency record could not be linked to its order.');
        }
    }

    public function complete(
        int $userId,
        string $keyHash,
        string $claimToken,
        int $statusCode,
        array $body
    ): void
    {
        $updated = $this->database->table('checkout_idempotency', 'stockflow')
            ->where('user_id', '=', $userId)
            ->where('key_hash', '=', $keyHash)
            ->where('claim_token', '=', $claimToken)
            ->where('status', '=', 'processing')
            ->update([
                'status' => 'completed',
                'response_status' => $statusCode,
                'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The checkout idempotency response could not be saved.');
        }
    }

    public function completeRecovered(
        int $userId,
        string $keyHash,
        int $orderId,
        int $statusCode,
        array $body
    ): void {
        $updated = $this->database->table('checkout_idempotency', 'stockflow')
            ->where('user_id', '=', $userId)
            ->where('key_hash', '=', $keyHash)
            ->where('status', '=', 'processing')
            ->where('order_id', '=', $orderId)
            ->update([
                'status' => 'completed',
                'response_status' => $statusCode,
                'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The recovered checkout response could not be saved.');
        }
    }

    public function release(int $userId, string $keyHash, string $claimToken): void
    {
        $this->database->table('checkout_idempotency', 'stockflow')
            ->where('user_id', '=', $userId)
            ->where('key_hash', '=', $keyHash)
            ->where('claim_token', '=', $claimToken)
            ->where('status', '=', 'processing')
            ->whereNull('order_id')
            ->delete();
    }

    private function find(int $userId, string $keyHash): ?array
    {
        return $this->database->table('checkout_idempotency', 'stockflow')
            ->where('user_id', '=', $userId)
            ->where('key_hash', '=', $keyHash)
            ->first();
    }
}
