<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\file_manager\src\Services;

use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authorisation + CSRF protection for the File Manager.
 *
 * Why this exists (found while reading the CMS source):
 *  - RouteProvider only verifies CSRF tokens on POSTs that are NOT XMLHttpRequests,
 *    so every AJAX write would be unprotected. We therefore verify our own token.
 *  - AuthMiddleware lets any admin/super_admin through every permission-gated route,
 *    so the route requirement alone does not restrict access. We re-check
 *    can_use_file_manager here via User::hasPermission() (always true for super_admin).
 */
final class RequestGuard
{
    public const PERMISSION = 'can_use_file_manager';

    /** @return object the current User entity */
    public static function requireUser(): object
    {
        $current = getAppContainer()->get('current_user');
        $user = $current?->getUser();
        if (!$user) {
            throw new FileManagerException('You must be logged in.', 401, null, 'auth');
        }
        if (!$user->hasPermission(self::PERMISSION)) {
            throw new FileManagerException('You do not have permission to use the file manager.', 403, null, 'forbidden');
        }
        return $user;
    }

    public static function token(Request $request): string
    {
        return Url::generateToken($request, self::secret());
    }

    /** Call on every state-changing request (POST). */
    public static function assertCsrf(Request $request): void
    {
        $secret = self::secret();

        // 1. Custom header: cross-site pages cannot set it without CORS approval.
        if (strtolower((string) $request->headers->get('X-Requested-With')) !== 'xmlhttprequest') {
            throw new FileManagerException('Invalid request.', 403, null, 'csrf');
        }

        // 2. Origin / Referer (when the browser sends one) must be this site.
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
                throw new FileManagerException('Request origin does not match this site.', 403, null, 'origin');
            }
        }

        // 3. HMAC token (same scheme the CMS uses; current or previous 10-minute window).
        $submitted = (string) $request->headers->get('X-CSRF-Token', '');
        if ($submitted === '') {
            $submitted = (string) $request->request->get('_csrf_token', '');
        }
        $ok = $submitted !== ''
            && (hash_equals(Url::generateToken($request, $secret, 0), $submitted)
                || hash_equals(Url::generateToken($request, $secret, -1), $submitted));
        if (!$ok) {
            throw new FileManagerException('Your security token expired.', 403, null, 'csrf');
        }
    }

    private static function secret(): string
    {
        $secret = (string) ($_ENV['CSRF_TOKEN_SECRET'] ?? getenv('CSRF_TOKEN_SECRET') ?: '');
        if ($secret === '') {
            throw new FileManagerException('CSRF_TOKEN_SECRET is not set in .env. The file manager refuses to run without it.', 500, null, 'config');
        }
        return $secret;
    }
}
