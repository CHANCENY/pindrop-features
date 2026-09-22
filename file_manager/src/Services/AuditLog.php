<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\file_manager\src\Services;

use Symfony\Component\HttpFoundation\Request;

/** Writes one line per state-changing action to the CMS logger. Never throws. */
final class AuditLog
{
    public static function write(string $level, string $action, object $user, Request $request, array $details = []): void
    {
        try {
            $logger = getAppContainer()->get('logger');
            $logger->log($level, 'file_manager: ' . $action, [
                'user_id'  => method_exists($user, 'getId') ? $user->getId() : null,
                'username' => method_exists($user, 'getUsername') ? $user->getUsername() : null,
                'ip'       => $request->getClientIp(),
            ] + $details);
        } catch (\Throwable) {
            // logging must never break a file operation
        }
    }
}
