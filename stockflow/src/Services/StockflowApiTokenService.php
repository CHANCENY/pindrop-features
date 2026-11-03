<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Services;

use InvalidArgumentException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\User;

final class StockflowApiTokenService
{
    public function __construct(private readonly DatabaseService $database)
    {
    }

    public function issue(int $userId, string $name): string
    {
        $name = trim($name);
        if ($userId < 1 || $name === '' || strlen($name) > 80) {
            throw new InvalidArgumentException('Enter a token name between 1 and 80 characters.');
        }

        $token = 'sf_live_' . bin2hex(random_bytes(32));
        $this->database->table('api_tokens', 'stockflow')->insert([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 16),
        ]);
        return $token;
    }

    public function forUser(int $userId): array
    {
        return $this->database->table('api_tokens', 'stockflow')
            ->select(['id', 'name', 'token_prefix', 'created_at', 'last_used_at', 'revoked_at'])
            ->where('user_id', '=', $userId)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    public function revoke(int $userId, int $tokenId): void
    {
        $revoked = $this->database->table('api_tokens', 'stockflow')
            ->where('id', '=', $tokenId)
            ->where('user_id', '=', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
        if ($revoked !== 1) {
            throw new InvalidArgumentException('This API token is no longer active.');
        }
    }

    public function authenticate(?string $authorizationHeader): ?User
    {
        if (
            $authorizationHeader === null
            || preg_match('/^Bearer (sf_live_[a-f0-9]{64})$/D', $authorizationHeader, $matches) !== 1
        ) {
            return null;
        }

        $tokenHash = hash('sha256', $matches[1]);
        $record = $this->database->table('api_tokens', 'stockflow')
            ->where('token_hash', '=', $tokenHash)
            ->whereNull('revoked_at')
            ->first();
        if ($record === null) {
            return null;
        }

        $user = User::loadById((int) $record['user_id'], $this->database);
        if ($user === null || !$user->canLogin() || !$user->hasPermission('can_manage_stockflow')) {
            return null;
        }

        $lastUsed = $this->database->table('api_tokens', 'stockflow')
            ->where('id', '=', (int) $record['id'])
            ->whereNull('revoked_at')
            ->update(['last_used_at' => date('Y-m-d H:i:s')]);
        if ($lastUsed !== 1) {
            return null;
        }
        return $user;
    }
}
