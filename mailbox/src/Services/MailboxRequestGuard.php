<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Services;

use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Same rationale as the file manager plugin's RequestGuard:
 *  - RouteProvider only checks CSRF on non-AJAX POSTs, so AJAX writes here
 *    (send, delete, move, flag, ...) need their own check.
 *  - AuthMiddleware lets any admin/super_admin through a permission-gated
 *    route, so the controller re-checks can_use_mailbox itself.
 */
final class MailboxRequestGuard
{
    public const PERMISSION = 'can_use_mailbox';

    public static function requireUser(): object
    {
        $current = getAppContainer()->get('current_user');
        $user = $current?->getUser();
        if (!$user) {
            throw new MailboxException('You must be logged in.', 401, 'auth');
        }
        if (!$user->hasPermission(self::PERMISSION)) {
            throw new MailboxException('You do not have permission to use the mailbox.', 403, 'forbidden');
        }
        return $user;
    }

    public static function token(Request $request): string
    {
        return Url::generateToken($request, self::secret());
    }

    public static function assertCsrf(Request $request): void
    {
        $secret = self::secret();

        if (strtolower((string) $request->headers->get('X-Requested-With')) !== 'xmlhttprequest') {
            throw new MailboxException('Invalid request.', 403, 'csrf');
        }

        $source = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if ($source) {
            $host = strtolower((string) parse_url($source, PHP_URL_HOST));
            $allowed = [];
            try {
                $allowed[] = strtolower($request->getHost());
            } catch (\Throwable) {
            }
            foreach (explode(',', (string) $request->headers->get('X-Forwarded-Host')) as $fwd) {
                $fwd = strtolower(trim(explode(':', trim($fwd))[0]));
                if ($fwd !== '') {
                    $allowed[] = $fwd;
                }
            }
            if ($host === '' || !in_array($host, $allowed, true)) {
                throw new MailboxException('Request origin does not match this site.', 403, 'origin');
            }
        }

        $submitted = (string) $request->headers->get('X-CSRF-Token', '');
        if ($submitted === '') {
            $submitted = (string) $request->request->get('_csrf_token', '');
        }
        $ok = $submitted !== ''
            && (hash_equals(Url::generateToken($request, $secret, 0), $submitted)
                || hash_equals(Url::generateToken($request, $secret, -1), $submitted));
        if (!$ok) {
            throw new MailboxException('Your security token expired.', 403, 'csrf');
        }
    }

    private static function secret(): string
    {
        $secret = (string) ($_ENV['CSRF_TOKEN_SECRET'] ?? getenv('CSRF_TOKEN_SECRET') ?: '');
        if ($secret === '') {
            throw new MailboxException('CSRF_TOKEN_SECRET is not set in .env. The mailbox plugin refuses to run without it.', 500, 'config');
        }
        return $secret;
    }
}
