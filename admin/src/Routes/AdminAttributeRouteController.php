<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\admin\src\Routes;

use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Mail\LocalMailServer;
use Simp\Pindrop\Routing\AttributeRoute;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class AdminAttributeRouteController extends ControllerBase
{
    private const LISTING_PATH = '/admin/server/mails';
    private const LIMITS = [10, 25, 50, 100];
    private const DEFAULT_LIMIT = 25;
    private const CSRF_KEY = '_local_mails_csrf';

    /** Flash messages that may be shown after a redirect (whitelist, never echo raw input). */
    private const STATUS_MESSAGES = [
        'deleted' => 'The mail was deleted.',
    ];

    #[AttributeRoute('/terms','GET',permission: [])]
    public function terms(Request $request, string $route_name, array $options){
        return $this->render("Terms of site");
    }

    /**
     * Admin page for the local mail store.
     *
     *   GET  /admin/server/mails                   -> paginated listing (?limit=25&page=1)
     *   GET  /admin/server/mails?id=12             -> single mail view
     *   POST /admin/server/mails  action=delete&id -> delete one mail (CSRF protected)
     */
    #[AttributeRoute("/admin/server/mails", ['GET', 'POST'], permission: ['can_manage_local_mails'])]
    public function localMailsListing(Request $request, string $route_name, array $options)
    {
        $mailServer = new LocalMailServer(getAppContainer()->get('database'));

        $limit = (int) ($this->input($request, 'limit') ?? self::DEFAULT_LIMIT);
        if (!in_array($limit, self::LIMITS, true)) {
            $limit = self::DEFAULT_LIMIT;
        }
        $page = max(1, (int) ($this->input($request, 'page') ?? 1));

        $error = null;

        // ── POST: actions ────────────────────────────────────────────────
        if ($request->isMethod('POST')) {
            if (!$this->isValidCsrf($request)) {
                $error = 'Your form token is invalid or has expired. Reload the page and try again.';
            } elseif ($request->request->get('action') === 'delete') {
                $id = (int) $request->request->get('id');

                if ($id > 0 && $mailServer->delete($id)) {
                    return new RedirectResponse(self::LISTING_PATH . '?' . http_build_query([
                        'status' => 'deleted',
                        'limit' => $limit,
                        'page' => $page,
                    ]));
                }

                $error = 'The mail could not be deleted.';
            } else {
                $error = 'Unknown action.';
            }
        }

        $baseUrl = self::LISTING_PATH;
        $listUrl = $baseUrl . '?' . http_build_query(['limit' => $limit, 'page' => $page]);

        $statusKey = (string) $request->query->get('status', '');
        $context = [
            'base_url' => $baseUrl,
            'list_url' => $listUrl,
            'limit' => $limit,
            'limits' => self::LIMITS,
            'page' => $page,
            'csrf_token' => $this->csrfToken($request),
            'error' => $error,
            'status_message' => self::STATUS_MESSAGES[$statusKey] ?? null,
            'mail' => null,
            'mails' => [],
            'total' => 0,
            'pages' => 1,
        ];

        // ── GET ?id=: single mail view ───────────────────────────────────
        $viewId = (int) $request->query->get('id', 0);
        if ($viewId > 0) {
            $mail = $mailServer->getMail($viewId);
            if ($mail === null) {
                $context['error'] = 'That mail was not found. It may have been deleted.';
            } else {
                $context['mail'] = $mail;
                return $this->renderTwig("@admin/emails/local_mails_listing.html.twig", $context);
            }
        }

        // ── Listing ──────────────────────────────────────────────────────
        $total = $mailServer->countMails();
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);

        $context['total'] = $total;
        $context['pages'] = $pages;
        $context['page'] = $page;
        $context['list_url'] = $baseUrl . '?' . http_build_query(['limit' => $limit, 'page' => $page]);
        $context['mails'] = $mailServer->getMails($limit, ($page - 1) * $limit);

        return $this->renderTwig("@admin/emails/local_mails_listing.html.twig", $context);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Read a value from the POST body first, then the query string. */
    private function input(Request $request, string $key): mixed
    {
        return $request->request->get($key) ?? $request->query->get($key);
    }

    /**
     * Per-session CSRF token. Uses the Symfony session when the request has one,
     * otherwise the native PHP session. Returns '' when no session is available,
     * in which case POST actions are refused (fail closed).
     */
    private function csrfToken(Request $request): string
    {
        if ($request->hasSession()) {
            $session = $request->getSession();
            $token = $session->get(self::CSRF_KEY);
            if (!is_string($token) || $token === '') {
                $token = bin2hex(random_bytes(32));
                $session->set(self::CSRF_KEY, $token);
            }
            return $token;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    private function isValidCsrf(Request $request): bool
    {
        $expected = $this->csrfToken($request);
        $given = (string) $request->request->get('_token', '');

        return $expected !== '' && hash_equals($expected, $given);
    }
}
