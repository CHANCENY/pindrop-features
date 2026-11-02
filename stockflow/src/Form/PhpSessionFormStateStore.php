<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Form;

use RuntimeException;
use Simp\Multiform\lib\wrapper\FormStateSessionStore;

final class PhpSessionFormStateStore implements FormStateSessionStore
{
    public function load(string $key): array
    {
        $this->ensureSession();
        return $_SESSION['multiform'][$key] ?? [];
    }

    public function save(string $key, array $values): void
    {
        $this->ensureSession();
        $_SESSION['multiform'][$key] = $values;
    }

    public function clear(string $key): void
    {
        $this->ensureSession();
        unset($_SESSION['multiform'][$key]);
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!session_start()) {
            throw new RuntimeException('Unable to start the session for the Stockflow product form.');
        }
    }
}
