<?php

namespace Simp\Pindrop\Modules\signals_slots\src\Controller;

use PDO;
use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\signals_slots\src\Service\SignalBus;
use Simp\Pindrop\Modules\signals_slots\src\Service\SignalRegistry;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SignalsController extends ControllerBase
{
    protected PDO $pdo;

    public function __construct(
        protected SignalBus       $signalBus,
        protected SignalRegistry  $signalRegistry,
        protected DatabaseService $databaseService
    ) {
        parent::__construct();
        $this->pdo = $this->databaseService->getPdo();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get(SignalBus::class),
            $container->get(SignalRegistry::class),
            $container->get('database')
        );
    }

    // ----------------------------------------------------------------
    // GET /admin/signals — dashboard
    // ----------------------------------------------------------------

    public function dashboard(Request $request, string $route_name, array $options): mixed
    {
        $connections = $this->databaseService->table('signal_connections')
            ->orderBy('created_at', 'DESC')
            ->get();

        // Attach human-readable names to each connection
        foreach ($connections as &$conn) {
            $sig = $this->signalRegistry->getSignal($conn['signal_key']);
            $slt = $this->signalRegistry->getSlot($conn['slot_id']);
            $conn['signal_name'] = $sig['name'] ?? $conn['signal_key'];
            $conn['slot_name']   = $slt['name'] ?? $conn['slot_id'];
        }
        unset($conn);

        // Queue depth
        $queueDepth = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM signal_queue WHERE status = 'pending'")
            ->fetchColumn();

        return $this->renderTwig('@signals_slots/signals_slots_dashboard.html.twig', [
            'page_title'  => 'Signals & Slots',
            'signals'     => $this->signalRegistry->getSignals(),
            'slots'       => $this->signalRegistry->getSlots(),
            'connections' => $connections,
            'queue_depth' => $queueDepth,
        ]);
    }

    // ----------------------------------------------------------------
    // GET /POST /admin/signals/connect — create a connection
    // ----------------------------------------------------------------

    public function connect(Request $request, string $route_name, array $options): mixed
    {
        if ($request->getMethod() === 'POST') {
            $signal = trim($request->request->get('signal_key', ''));
            $slot   = trim($request->request->get('slot_id', ''));
            $mode   = $request->request->get('mode', 'sync') === 'async' ? 'async' : 'sync';

            if (!$signal || !$slot) {
                Message::warn('Signal and slot are both required.');
                return $this->redirect(Url::routeByName('signals_slots.connect'));
            }

            // Check for duplicate
            $existing = $this->databaseService->table('signal_connections')
                ->where('signal_key', '=', $signal)
                ->where('slot_id', '=', $slot)
                ->first();

            if ($existing) {
                Message::warn("A connection between '{$signal}' and '{$slot}' already exists.");
                return $this->redirect(Url::routeByName('signals_slots.dashboard'));
            }

            $this->databaseService->table('signal_connections')->insert([
                'signal_key' => $signal,
                'slot_id'    => $slot,
                'mode'       => $mode,
                'active'     => 1,
            ]);

            Message::info("Connected signal '{$signal}' → slot '{$slot}' ({$mode}).");
            return $this->redirect(Url::routeByName('signals_slots.dashboard'));
        }

        return $this->renderTwig('@signals_slots/connect.html.twig', [
            'page_title' => 'Connect Signal → Slot',
            'signals'    => $this->signalRegistry->getSignals(),
            'slots'      => $this->signalRegistry->getSlots(),
        ]);
    }

    // ----------------------------------------------------------------
    // POST /admin/signals/[id:int]/toggle
    // ----------------------------------------------------------------

    public function toggle(Request $request, string $route_name, array $options): mixed
    {
        $id   = (int) $options['id'];
        $conn = $this->databaseService->table('signal_connections')
            ->where('id', '=', $id)
            ->first();

        if ($conn) {
            $this->databaseService->table('signal_connections')
                ->where('id', '=', $id)
                ->update(['active' => $conn['active'] ? 0 : 1]);

            Message::info($conn['active'] ? 'Connection paused.' : 'Connection activated.');
        }

        return $this->redirect(Url::routeByName('signals_slots.dashboard'));
    }

    // ----------------------------------------------------------------
    // POST /admin/signals/[id:int]/delete
    // ----------------------------------------------------------------

    public function delete(Request $request, string $route_name, array $options): mixed
    {
        $this->databaseService->table('signal_connections')
            ->where('id', '=', (int) $options['id'])
            ->delete();

        Message::info('Connection deleted.');
        return $this->redirect(Url::routeByName('signals_slots.dashboard'));
    }

    // ----------------------------------------------------------------
    // GET /admin/signals/log — delivery log
    // ----------------------------------------------------------------

    public function log(Request $request, string $route_name, array $options): mixed
    {
        $perPage = 50;
        $page    = max(1, (int) $request->query->get('page', 1));
        $offset  = ($page - 1) * $perPage;

        $filters = array_filter([
            'signal_key' => $request->query->get('signal_key'),
            'slot_id'    => $request->query->get('slot_id'),
            'success'    => $request->query->has('success') ? $request->query->get('success') : null,
        ], fn($v) => $v !== null && $v !== '');

        [$where, $params] = $this->buildLogWhere($filters);

        $stmt = $this->pdo->prepare("
            SELECT * FROM signal_delivery_log {$where}
            ORDER BY created_at DESC LIMIT :lim OFFSET :off
        ");
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $count = (int) $this->pdo->prepare("SELECT COUNT(*) FROM signal_delivery_log {$where}")
            ->execute($params) ? $this->pdo->prepare("SELECT COUNT(*) FROM signal_delivery_log {$where}") : 0;

        $cStmt = $this->pdo->prepare("SELECT COUNT(*) FROM signal_delivery_log {$where}");
        foreach ($params as $k => $v) { $cStmt->bindValue($k, $v); }
        $cStmt->execute();
        $total = (int) $cStmt->fetchColumn();

        return $this->renderTwig('@signals_slots/signals_slots_log.html.twig', [
            'page_title' => 'Delivery Log',
            'entries'    => $entries,
            'filters'    => $filters,
            'pagination' => [
                'current' => $page,
                'total'   => (int) ceil($total / $perPage),
                'count'   => $total,
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // POST /admin/signals/emit-test  (developer convenience — non-production)
    // ----------------------------------------------------------------

    public function emitTest(Request $request, string $route_name, array $options): mixed
    {
        $signal  = $request->request->get('signal_key', '');
        $payload = json_decode($request->request->get('payload', '{}'), true) ?? [];

        if (!$signal) {
            return new JsonResponse(['error' => 'signal_key required'], 400);
        }

        $this->signalBus->emit($signal, $payload);
        return new JsonResponse(['ok' => true, 'signal' => $signal]);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function buildLogWhere(array $filters): array
    {
        $conds  = [];
        $params = [];

        if (!empty($filters['signal_key'])) {
            $conds[]              = 'signal_key = :f_sig';
            $params[':f_sig']     = $filters['signal_key'];
        }
        if (!empty($filters['slot_id'])) {
            $conds[]              = 'slot_id = :f_slot';
            $params[':f_slot']    = $filters['slot_id'];
        }
        if (!empty($filters['success']) && $filters['success'] !== null && $filters['success'] !== '') {
            $conds[]              = 'success = :f_ok';
            $params[':f_ok']      = (int) $filters['success'];
        }

        return [
            empty($conds) ? '' : 'WHERE ' . implode(' AND ', $conds),
            $params,
        ];
    }
}
