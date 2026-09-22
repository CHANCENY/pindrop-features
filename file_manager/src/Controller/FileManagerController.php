<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\file_manager\src\Controller;

use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\file_manager\src\Http\FileDownloadResponse;
use Simp\Pindrop\Modules\file_manager\src\Plugin\FileManagerSettings;
use Simp\Pindrop\Modules\file_manager\src\Services\AuditLog;
use Simp\Pindrop\Modules\file_manager\src\Services\FileManagerException;
use Simp\Pindrop\Modules\file_manager\src\Services\FileManagerService;
use Simp\Pindrop\Modules\file_manager\src\Services\RequestGuard;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Page + JSON API for the File Manager.
 *
 * NOTE: the CMS builds a controller instance for every route on every request,
 * so this class deliberately has no constructor work and creates the
 * FileManagerService lazily, only when an action actually runs.
 */
class FileManagerController extends ControllerBase
{
    /** Image types that may be previewed inline. SVG is excluded on purpose (can carry script). */
    private const INLINE_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'avif' => 'image/avif',
    ];

    // ------------------------------------------------------------------
    //  Page
    // ------------------------------------------------------------------

    public function index(Request $request, string $route_name, array $options): Response
    {
        try {
            $user = RequestGuard::requireUser();
            $cfg = FileManagerSettings::resolve();
            $fm = new FileManagerService($cfg['root'], $cfg['options']);
        } catch (FileManagerException $e) {
            return $this->renderTwig('@file_manager/denied.twig', [
                'page_title' => 'File Manager',
                'message'    => $e->getMessage(),
                'user'       => $this->userArray(),
            ], $this->status($e));
        }

        $start = '';
        try {
            $start = $fm->normalizeRel((string) $request->query->get('path', ''));
        } catch (FileManagerException) {
        }

        $config = [
            'urls' => [
                'list'     => Url::routeByName('file_manager.api.list'),
                'search'   => Url::routeByName('file_manager.api.search'),
                'read'     => Url::routeByName('file_manager.api.read'),
                'download' => Url::routeByName('file_manager.api.download'),
                'token'    => Url::routeByName('file_manager.api.token'),
                'action'   => Url::routeByName('file_manager.api.action'),
                'upload'   => Url::routeByName('file_manager.api.upload'),
            ],
            'token'      => RequestGuard::token($request),
            'limits'     => $fm->getLimits() + [
                'php_upload_max' => $this->iniBytes('upload_max_filesize'),
                'php_post_max'   => $this->iniBytes('post_max_size'),
            ],
            'showHidden' => $cfg['show_hidden'],
            'startPath'  => $start,
            'root'       => $fm->getRoot(),
            'warning'    => $cfg['warning'],
        ];

        return $this->renderTwig('@file_manager/index.twig', [
            'page_title'  => 'File Manager',
            'user'        => $this->userArray(),
            'config_json' => json_encode(
                $config,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            'read_only'   => $fm->isReadOnly(),
            'root'        => $fm->getRoot(),
        ]);
    }

    // ------------------------------------------------------------------
    //  Read-only JSON endpoints (GET)
    // ------------------------------------------------------------------

    public function list(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, false, '', function (FileManagerService $fm) use ($request) {
            return $fm->list((string) $request->query->get('path', ''), $this->showHidden($request));
        });
    }

    public function search(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, false, '', function (FileManagerService $fm) use ($request) {
            return $fm->search(
                (string) $request->query->get('path', ''),
                (string) $request->query->get('q', ''),
                $this->showHidden($request)
            );
        });
    }

    public function read(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, false, '', function (FileManagerService $fm) use ($request) {
            return $fm->read((string) $request->query->get('path', ''));
        });
    }

    /** Fresh CSRF token for long-lived pages (tokens live 10-20 minutes). */
    public function token(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, false, '', fn() => ['token' => RequestGuard::token($request)]);
    }

    public function download(Request $request, string $route_name, array $options): Response
    {
        try {
            $user = RequestGuard::requireUser();
            $fm = $this->service();
            $abs = $fm->downloadPath((string) $request->query->get('path', ''));
            $name = basename($abs);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $inline = $request->query->get('inline') === '1' && isset(self::INLINE_TYPES[$ext]);

            $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name);
            $fallback = str_replace(['%', '/', '\\', '"'], '_', (string) $fallback);
            if ($fallback === '') {
                $fallback = 'download';
            }

            if (!$inline) {
                AuditLog::write('info', 'download', $user, $request, ['path' => $request->query->get('path')]);
            }

            return new FileDownloadResponse($abs, 200, [
                'Content-Type'            => $inline ? self::INLINE_TYPES[$ext] : 'application/octet-stream',
                'Content-Length'          => (string) filesize($abs),
                'Content-Disposition'     => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $name, $fallback),
                'X-Content-Type-Options'  => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control'           => 'private, no-store',
            ]);
        } catch (FileManagerException $e) {
            return new Response($e->getMessage(), $this->status($e), ['Content-Type' => 'text/plain; charset=UTF-8']);
        } catch (\Throwable $e) {
            return new Response('Unexpected server error.', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }

    // ------------------------------------------------------------------
    //  State-changing endpoints (POST, CSRF-protected)
    // ------------------------------------------------------------------

    public function action(Request $request, string $route_name, array $options): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $action = is_array($body) && is_string($body['action'] ?? null) ? $body['action'] : '';

        return $this->respond($request, true, $action, function (FileManagerService $fm, object $user) use ($request, $body, $action) {
            if (!is_array($body) || $action === '') {
                throw new FileManagerException('Invalid request.', 400);
            }
            $str = static fn(string $k): string => is_string($body[$k] ?? null) ? $body[$k] : '';
            $list = static function (string $k) use ($body): array {
                $v = $body[$k] ?? null;
                if (!is_array($v) || $v === [] || count($v) > 1000) {
                    throw new FileManagerException('Select between 1 and 1000 items.', 400);
                }
                foreach ($v as $item) {
                    if (!is_string($item)) {
                        throw new FileManagerException('Invalid request.', 400);
                    }
                }
                return array_values($v);
            };

            $result = match ($action) {
                'mkdir'  => $fm->createDir($str('path'), $str('name')),
                'touch'  => $fm->createFile($str('path'), $str('name')),
                'rename' => $fm->rename($str('path'), $str('name')),
                'delete' => $fm->delete($list('paths')),
                'move'   => $fm->move($list('paths'), $str('dest')),
                'copy'   => $fm->copy($list('paths'), $str('dest')),
                'zip'    => $fm->zip($list('paths'), $str('dest'), $str('name')),
                'unzip'  => $fm->unzip($str('path'), $str('dest'), !empty($body['overwrite'])),
                'chmod'  => $fm->chmod($str('path'), $str('mode')),
                'save'   => $this->save($fm, $body),
                default  => throw new FileManagerException('Unknown action.', 400),
            };

            // Never log file contents - only what was touched.
            $details = array_filter([
                'path'  => $body['path'] ?? null,
                'paths' => $body['paths'] ?? null,
                'dest'  => $body['dest'] ?? null,
                'name'  => $body['name'] ?? null,
                'mode'  => $body['mode'] ?? null,
            ], fn($v) => $v !== null && $v !== '');
            AuditLog::write('info', $action, $user, $request, $details);

            return $result;
        });
    }

    private function save(FileManagerService $fm, array $body): array
    {
        if (!is_string($body['path'] ?? null) || !is_string($body['content'] ?? null)) {
            throw new FileManagerException('Invalid request.', 400);
        }
        $mtime = isset($body['mtime']) && is_numeric($body['mtime']) ? (int) $body['mtime'] : null;
        return $fm->save($body['path'], $body['content'], $mtime, !empty($body['force']));
    }

    public function upload(Request $request, string $route_name, array $options): Response
    {
        return $this->respond($request, true, 'upload', function (FileManagerService $fm, object $user) use ($request) {
            $incoming = $request->files->get('files');
            if ($incoming === null || $incoming === []) {
                throw new FileManagerException(
                    'No file arrived. It is probably larger than the server limit (post_max_size = ' . ini_get('post_max_size') . ').',
                    413
                );
            }
            $files = [];
            foreach (is_array($incoming) ? $incoming : [$incoming] as $f) {
                if (!$f instanceof UploadedFile) {
                    continue;
                }
                $err = $f->getError();
                $files[] = [
                    'name'     => $f->getClientOriginalName(),
                    'tmp_name' => $err === UPLOAD_ERR_OK ? $f->getPathname() : '',
                    'size'     => $err === UPLOAD_ERR_OK ? (int) $f->getSize() : 0,
                    'error'    => $err,
                ];
            }
            $dest = (string) $request->request->get('path', '');
            $overwrite = $request->request->get('overwrite') === '1';
            $result = $fm->upload($files, $dest, $overwrite);

            AuditLog::write('info', 'upload', $user, $request, [
                'dest'   => $dest,
                'stored' => $result['done'],
                'failed' => array_column($result['errors'], 'name'),
            ]);
            return $result;
        });
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Common wrapper: authorise, (for POST) verify CSRF, run, JSON-encode, map errors.
     *
     * @param callable(FileManagerService, object): array $fn
     */
    private function respond(Request $request, bool $mutating, string $label, callable $fn): Response
    {
        $user = null;
        try {
            $user = RequestGuard::requireUser();
            if ($mutating) {
                RequestGuard::assertCsrf($request);
            }
            $data = $fn($this->service(), $user);
            return $this->jsonOut(['ok' => true, 'data' => $data]);
        } catch (FileManagerException $e) {
            if ($mutating && $user !== null && !in_array($e->reason, ['csrf', 'origin', 'auth'], true)) {
                AuditLog::write('warning', ($label !== '' ? $label : 'action') . ' refused', $user, $request, ['reason' => $e->getMessage()]);
            }
            return $this->jsonOut(['ok' => false, 'error' => $e->getMessage(), 'reason' => $e->reason], $this->status($e));
        } catch (\Throwable $e) {
            try {
                getAppContainer()->get('logger')->log('error', 'file_manager: unexpected error', [
                    'error' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine(),
                ]);
            } catch (\Throwable) {
            }
            $debug = (getenv('APP_ENV') ?: 'development') !== 'production';
            return $this->jsonOut([
                'ok'     => false,
                'error'  => $debug ? 'Unexpected server error: ' . $e->getMessage() : 'Unexpected server error.',
                'reason' => 'server',
            ], 500);
        }
    }

    /** Protected so tests can substitute a service that accepts non-HTTP uploads. */
    protected function service(): FileManagerService
    {
        $cfg = FileManagerSettings::resolve();
        return new FileManagerService($cfg['root'], $cfg['options']);
    }

    private function showHidden(Request $request): bool
    {
        $q = $request->query->get('hidden');
        if ($q === '1' || $q === '0') {
            return $q === '1';
        }
        return FileManagerSettings::resolve()['show_hidden'];
    }

    private function status(FileManagerException $e): int
    {
        $c = $e->getCode();
        return ($c >= 400 && $c <= 599) ? $c : 400;
    }

    private function jsonOut(array $payload, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions(
            JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
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

    private function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '' || $v === '-1') {
            return 0; // unlimited / unknown
        }
        $n = (float) $v;
        return (int) match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
