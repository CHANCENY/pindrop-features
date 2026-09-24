<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\mailbox\src\Controller;

use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\mailbox\src\Plugin\MailboxSettings;
use Simp\Pindrop\Modules\mailbox\src\Services\MailboxException;
use Simp\Pindrop\Modules\mailbox\src\Services\MailboxManager;
use Simp\Pindrop\Modules\mailbox\src\Services\MailboxRequestGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MailboxController extends ControllerBase
{
    // ------------------------------------------------------------------
    //  Page
    // ------------------------------------------------------------------

    public function index(Request $request, string $route_name, array $options): Response
    {
        try {
            MailboxRequestGuard::requireUser();
        } catch (MailboxException $e) {
            return $this->renderTwig('@mailbox/denied.twig', ['message' => $e->getMessage(), 'user' => $this->userArray()], $this->status($e));
        }

        if (!MailboxSettings::isConfigured()) {
            return $this->renderTwig('@mailbox/setup.twig', ['user' => $this->userArray()], 200);
        }

        $config = [
            'urls' => [
                'folders' => \Simp\Pindrop\Routing\Url::routeByName('mailbox.api.folders'),
                'messages' => \Simp\Pindrop\Routing\Url::routeByName('mailbox.api.messages'),
                'message' => \Simp\Pindrop\Routing\Url::routeByName('mailbox.api.message'),
                'attachment' => \Simp\Pindrop\Routing\Url::routeByName('mailbox.api.attachment'),
                'token' => \Simp\Pindrop\Routing\Url::routeByName('mailbox.api.token'),
                'action' => \Simp\Pindrop\Routing\Url::routeByName('mailbox.api.action'),
                'send' => \Simp\Pindrop\Routing\Url::routeByName('mailbox.api.send'),
            ],
            'token' => MailboxRequestGuard::token($request),
            'accountEmail' => MailboxSettings::resolveAccount()['username'] ?? '',
        ];

        return $this->renderTwig('@mailbox/index.twig', [
            'page_title' => 'Mailbox',
            'user' => $this->userArray(),
            'config_json' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    // ------------------------------------------------------------------
    //  Read-only JSON endpoints
    // ------------------------------------------------------------------

    public function folders(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, false, '', fn(MailboxManager $m) => $m->listFolders());
    }

    public function messages(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, false, '', function (MailboxManager $m) use ($request) {
            $folder = (string) $request->query->get('folder', 'INBOX');
            $page = max(1, (int) $request->query->get('page', 1));
            $q = $request->query->get('q');
            $unseen = $request->query->get('unseen') === '1';
            $perPage = MailboxSettings::resolveAccount()['messagesPerPage'];
            return $m->listMessages($folder, $page, $perPage, $q !== null ? (string) $q : null, $unseen);
        });
    }

    public function message(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, false, '', function (MailboxManager $m) use ($request) {
            [$folder, $uid] = $this->folderUid($request->query);
            return $m->getMessage($folder, $uid);
        });
    }

    public function token(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, false, '', fn() => ['token' => MailboxRequestGuard::token($request)]);
    }

    public function attachment(Request $request, string $route_name, array $options): Response
    {
        try {
            MailboxRequestGuard::requireUser();
            [$folder, $uid] = $this->folderUid($request->query);
            $index = (int) $request->query->get('index', -1);
            if ($index < 0) {
                throw new MailboxException('Missing attachment index.', 400, 'bad-request');
            }
            $att = $this->manager()->getAttachment($folder, $uid, $index);
            $fallback = preg_replace('/[^\x20-\x7E]/', '_', $att['filename']) ?: 'attachment';
            return new Response($att['data'], 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) strlen($att['data']),
                'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $att['filename'], $fallback),
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (MailboxException $e) {
            return new Response($e->getMessage(), $this->status($e), ['Content-Type' => 'text/plain; charset=UTF-8']);
        } catch (\Throwable) {
            return new Response('Unexpected server error.', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }

    // ------------------------------------------------------------------
    //  State-changing endpoints
    // ------------------------------------------------------------------

    public function action(Request $request, string $route_name, array $options): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $action = is_array($body) && is_string($body['action'] ?? null) ? $body['action'] : '';

        return $this->respond($request, true, $action, function (MailboxManager $m) use ($body, $action) {
            if (!is_array($body) || $action === '') {
                throw new MailboxException('Invalid request.', 400, 'bad-request');
            }
            $str = static fn(string $k, string $default = ''): string => is_string($body[$k] ?? null) ? $body[$k] : $default;
            $uids = static function () use ($body): array {
                $v = $body['uids'] ?? null;
                if (!is_array($v) || $v === [] || count($v) > 500) {
                    throw new MailboxException('Select between 1 and 500 messages.', 400, 'bad-request');
                }
                $out = [];
                foreach ($v as $u) {
                    if (!is_numeric($u)) {
                        throw new MailboxException('Invalid message id.', 400, 'bad-request');
                    }
                    $out[] = (int) $u;
                }
                return $out;
            };
            $folder = static fn(): string => $str('folder');

            return match ($action) {
                'flag.set' => $this->flagResult($m, $folder(), $uids(), $str('flag'), true),
                'flag.unset' => $this->flagResult($m, $folder(), $uids(), $str('flag'), false),
                'move' => $this->voidResult($m, 'move', $folder(), $uids(), $str('dest')),
                'copy' => $this->voidResult($m, 'copy', $folder(), $uids(), $str('dest')),
                'delete' => $m->delete($folder(), $uids()),
                'trash.empty' => ['deleted' => $m->emptyTrash()],
                'folder.create' => $this->folderNameResult($m, 'createFolder', $str('name')),
                'folder.rename' => $this->folderRenameResult($m, $folder(), $str('name')),
                'folder.delete' => $this->folderNameResult($m, 'deleteFolder', $folder()),
                default => throw new MailboxException('Unknown action.', 400, 'bad-request'),
            };
        });
    }

    private function flagResult(MailboxManager $m, string $folder, array $uids, string $flag, bool $on): array
    {
        $m->setFlag($folder, $uids, $flag, $on);
        return ['ok' => true];
    }

    private function voidResult(MailboxManager $m, string $method, string $folder, array $uids, string $dest): array
    {
        if ($dest === '') {
            throw new MailboxException('A destination folder is required.', 400, 'bad-request');
        }
        $m->$method($folder, $uids, $dest);
        return ['ok' => true];
    }

    private function folderNameResult(MailboxManager $m, string $method, string $name): array
    {
        if (trim($name) === '') {
            throw new MailboxException('A folder name is required.', 400, 'bad-request');
        }
        $m->$method($name);
        return ['ok' => true];
    }

    private function folderRenameResult(MailboxManager $m, string $old, string $new): array
    {
        if (trim($old) === '' || trim($new) === '') {
            throw new MailboxException('Both the current and new folder names are required.', 400, 'bad-request');
        }
        $m->renameFolder($old, $new);
        return ['ok' => true];
    }

    public function send(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, true, 'send', function (MailboxManager $m) use ($request) {
            $isMultipart = str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/form-data');
            $body = $isMultipart
                ? json_decode((string) $request->request->get('payload', '{}'), true)
                : json_decode((string) $request->getContent(), true);
            if (!is_array($body)) {
                throw new MailboxException('Invalid request.', 400, 'bad-request');
            }

            $addrList = static function ($v): array {
                if (!is_array($v)) {
                    return [];
                }
                $out = [];
                foreach ($v as $a) {
                    if (is_array($a) && is_string($a['email'] ?? null) && filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
                        $out[] = ['name' => is_string($a['name'] ?? null) ? $a['name'] : null, 'email' => $a['email']];
                    } elseif (is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL)) {
                        $out[] = ['name' => null, 'email' => $a];
                    }
                }
                return $out;
            };

            $attachments = [];
            if (is_array($body['forwardAttachments'] ?? null)) {
                foreach ($body['forwardAttachments'] as $fa) {
                    if (is_array($fa) && isset($fa['folder'], $fa['uid'], $fa['index'])) {
                        $src = $m->getAttachment((string) $fa['folder'], (int) $fa['uid'], (int) $fa['index']);
                        $attachments[] = ['data' => $src['data'], 'name' => $src['filename'], 'type' => $src['contentType']];
                    }
                }
            }
            if ($isMultipart) {
                foreach ($request->files->all('files') ?: [] as $f) {
                    if ($f instanceof UploadedFile && $f->getError() === UPLOAD_ERR_OK) {
                        $attachments[] = ['path' => $f->getPathname(), 'name' => $f->getClientOriginalName(), 'type' => $f->getClientMimeType()];
                    }
                }
            }

            return $m->send([
                'to' => $addrList($body['to'] ?? []),
                'cc' => $addrList($body['cc'] ?? []),
                'bcc' => $addrList($body['bcc'] ?? []),
                'subject' => is_string($body['subject'] ?? null) ? $body['subject'] : '',
                'bodyHtml' => is_string($body['bodyHtml'] ?? null) ? $body['bodyHtml'] : null,
                'bodyText' => is_string($body['bodyText'] ?? null) ? $body['bodyText'] : '',
                'inReplyTo' => is_string($body['inReplyTo'] ?? null) ? $body['inReplyTo'] : null,
                'references' => is_array($body['references'] ?? null) ? array_values(array_filter($body['references'], 'is_string')) : [],
                'attachments' => $attachments,
            ]);
        });
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** Protected so tests can substitute a manager wired to a fake IMAP/DB backend. */
    protected function manager(): MailboxManager
    {
        return MailboxManager::fromAccount(MailboxSettings::resolveAccount());
    }

    private function folderUid(\Symfony\Component\HttpFoundation\ParameterBag $query): array
    {
        $folder = (string) $query->get('folder', '');
        $uid = (int) $query->get('uid', 0);
        if ($folder === '' || $uid <= 0) {
            throw new MailboxException('A folder and message id are required.', 400, 'bad-request');
        }
        return [$folder, $uid];
    }

    private function respond(Request $request, bool $mutating, string $label, callable $fn): Response
    {
        try {
            MailboxRequestGuard::requireUser();
            if ($mutating) {
                MailboxRequestGuard::assertCsrf($request);
            }
            $data = $fn($this->manager());
            return $this->jsonOut(['ok' => true, 'data' => $data]);
        } catch (MailboxException $e) {
            return $this->jsonOut(['ok' => false, 'error' => $e->getMessage(), 'reason' => $e->reason], $this->status($e));
        } catch (\Throwable $e) {
            try {
                getAppContainer()->get('logger')->log('error', 'mailbox: unexpected error', ['error' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]);
            } catch (\Throwable) {
            }
            $debug = (getenv('APP_ENV') ?: 'development') !== 'production';
            return $this->jsonOut(['ok' => false, 'error' => $debug ? 'Unexpected server error: ' . $e->getMessage() : 'Unexpected server error.', 'reason' => 'server'], 500);
        }
    }

    private function status(MailboxException $e): int
    {
        $c = $e->getCode();
        return ($c >= 400 && $c <= 599) ? $c : 400;
    }

    private function jsonOut(array $payload, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $response->setData($payload);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    private function userArray(): array
    {
        try {
            $user = getAppContainer()->get('current_user')?->getUser();
            return $user ? $user->toArray() : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
