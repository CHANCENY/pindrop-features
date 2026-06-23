<?php

namespace Simp\Pindrop\Modules\audit_log\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\audit_log\src\Service\AuditLogService;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AuditLogController extends ControllerBase
{
    public function __construct(protected AuditLogService $auditLogService)
    {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static($container->get(AuditLogService::class));
    }

    // ----------------------------------------------------------------
    // GET /admin/audit-log
    // ----------------------------------------------------------------

    public function list(Request $request, string $route_name, array $options): mixed
    {
        $perPage = 50;
        $page    = max(1, (int) $request->query->get('page', 1));
        $offset  = ($page - 1) * $perPage;

        $filters = array_filter([
            'user_id'   => $request->query->get('user_id'),
            'action'    => $request->query->get('action'),
            'severity'  => $request->query->get('severity'),
            'date_from' => $request->query->get('date_from'),
            'date_to'   => $request->query->get('date_to'),
        ]);

        $entries = $this->auditLogService->findAll($filters, $perPage, $offset);
        $total   = $this->auditLogService->countAll($filters);
        $pages   = (int) ceil($total / $perPage);


        $response = $this->renderTwig('@audit_log/list_audit_logs.twig', [
            'page_title' => 'Audit Log',
            'entries'    => $entries,
            'filters'    => $filters,
            'pagination' => [
                'current' => $page,
                'total'   => $pages,
                'count'   => $total,
                'per_page'=> $perPage,
            ],
        ]);

        return $response;
    }

    // ----------------------------------------------------------------
    // POST /admin/audit-log/purge
    // ----------------------------------------------------------------

    public function purge(Request $request, string $route_name, array $options): mixed
    {
        if ($request->getMethod() !== 'POST') {
            return $this->redirect(Url::routeByName('audit_log.list'));
        }

        $deleted = $this->auditLogService->purgeByRetentionPolicy();

        Message::info("Purged {$deleted} audit log " . ($deleted === 1 ? 'entry' : 'entries') . '.');

        return $this->redirect(Url::routeByName('audit_log.list'));
    }

    // ----------------------------------------------------------------
    // GET /internal/audit-log/entries  (JSON — for async table refresh)
    // ----------------------------------------------------------------

    public function apiEntries(Request $request, string $route_name, array $options): mixed
    {
        $filters = array_filter([
            'user_id'   => $request->query->get('user_id'),
            'action'    => $request->query->get('action'),
            'severity'  => $request->query->get('severity'),
            'date_from' => $request->query->get('date_from'),
            'date_to'   => $request->query->get('date_to'),
        ]);

        $limit   = min(200, max(1, (int) $request->query->get('limit', 50)));
        $offset  = max(0, (int) $request->query->get('offset', 0));

        return new JsonResponse([
            'entries' => $this->auditLogService->findAll($filters, $limit, $offset),
            'total'   => $this->auditLogService->countAll($filters),
        ]);
    }
}
