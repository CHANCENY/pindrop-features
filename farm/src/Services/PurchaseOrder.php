<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;
use Throwable;

class PurchaseOrder
{
    protected string $purchase_order = "purchase_orders";

    protected string $purchase_order_item = "purchase_order_items";

    protected array $status = ['Draft', 'Submitted', 'Awaiting Delivery', 'Received', 'Delayed', 'Cancelled'];

    public function __construct(protected DatabaseService $database_service, protected LoggerInterface $logger_interface)
    {
    }

    public function addPurchaseOrder(array $data): ?int
    {
        if (isset($data['status']) && !in_array($data['status'], $this->status)) {
            return null;
        }

        return $this->database_service->table($this->purchase_order)->insert($data);
    }

    public function updatePurchaseOrder(int $id, array $data): bool
    {
        if (isset($data['status']) && !in_array($data['status'], $this->status)) {
            return false;
        }

        return $this->database_service->table($this->purchase_order)->where('po_id', '=', $id)->update($data);
    }

    public function deletePurchaseOrder(int $id): bool
    {
        return $this->database_service->table($this->purchase_order)->where('po_id', '=', $id)->delete();
    }

    public function addPurchaseOrderItems(int $po_id, array $items): bool
    {
        try {
            foreach ($items as $item) {
                $item['po_id'] = $po_id;
                $this->database_service->table($this->purchase_order_item)->insert($item);
            }
            return true;
        } catch (Throwable $e) {

            return false;
        }

    }

    public function resetPurchaseOrderItems(int $po_id, array $items): bool
    {
        $this->database_service->table($this->purchase_order_item)->where('po_id', '=', $po_id)->delete();
        return $this->addPurchaseOrderItems($po_id, $items);
    }

    public function reCalculatePurchaseOrder(int $id): bool
    {
        try {

            $total_amount = $this->database_service->table($this->purchase_order_item)
                ->where('po_id', '=', $id)->select(["SUM(line_total) AS total"])->first()['total'] ?? 0;
            return $this->database_service->table($this->purchase_order)
                ->where('po_id', '=', $id)->update(['total_amount' => $total_amount]);
        } catch (Throwable) {
            return false;
        }
    }

    public function getPurchaseOrders(): array
    {
        return $this->database_service->table($this->purchase_order)->orderBy('created_at', 'DESC')->get();
    }

    public function getPurchaseOrder(int $id): ?array
    {
        return $this->database_service->table($this->purchase_order)->where('po_id', '=', $id)->first();
    }

    public function getPurchaseOrderByStatus(string $status): array
    {
        return $this->database_service->table($this->purchase_order)->where('status', '=', $status)->get();
    }

    public function getPurchaseOrderByPONumber(string $po_number): ?array
    {
        return $this->database_service->table($this->purchase_order)->where('po_number', '=', $po_number)->first();
    }

    public function getPurchaseOrderStatics(?string $year = null): array
    {

        $filter_year = empty($year) ? new \DateTime('now')->format('Y') : $year;

        $startDate = "{$filter_year}-01-01";
        $endDate = "{$filter_year}-12-31";

        $st = [
            'open_orders' => $this->database_service->table($this->purchase_order)->whereIn('status', ['Draft', 'Submitted', 'Awaiting Delivery'])
                ->whereBetween('order_date', $startDate, $endDate)
                ->select(["COUNT(po_id) AS total"])->first()['total'] ?? 0,
            'open_total_amount' => $this->database_service->table($this->purchase_order)->whereIn('status', ['Draft', 'Submitted', 'Awaiting Delivery'])
                ->whereBetween('order_date', $startDate, $endDate)
                ->select(["SUM(total_amount) AS total"])->first()['total'] ?? 0,
            'delayed_orders' => $this->database_service->table($this->purchase_order)->where('status', '=', 'Delayed')
                ->whereBetween('order_date', $startDate, $endDate)
                ->select(["COUNT(po_id) AS total"])->first()['total'] ?? 0,
            'received_orders' => $this->database_service->table($this->purchase_order)->where('status', '=', 'Received')
                ->whereBetween('order_date', $startDate, $endDate)
                ->select(["COUNT(po_id) AS total"])->first()['total'] ?? 0,
        ];

        $st['year'] = $filter_year;
        return $st;
    }

    public function getPurchaseOrderItems(int $po_id): array
    {
        return $this->database_service->table($this->purchase_order_item)->where('po_id', '=', $po_id)->get();
    }

    public function addTransactions(string $po_id): ?bool
    {
        $purchase = $this->getPurchaseOrder($po_id);
        $item_names = array_column($this->getPurchaseOrderItems($po_id), 'item_name');

        try {

            $transaction = new Transaction($this->database_service, $this->logger_interface);

            $oldTransaction = $transaction->getTransactionByEntityName($purchase['po_number']);
            if (empty($oldTransaction)) {

                return $transaction->addTransaction([
                    'transaction_type' => $purchase['purchase_type'],
                    'transaction_date' => $purchase['order_date'],
                    'category' => 'Livestock sale',
                    'description' => "Livestock sold (" . implode(',', $item_names).")",

                    'entity_name' => $purchase['po_number'],
                    'amount' => $purchase['total_amount'],
                    'payment_method' => 'Purchase Order',
                    'status' => $purchase['status'] === 'Received' ? 'Cleared' : 'Pending'
                ]);
            }

            return $this->updatePurchaseOrder($oldTransaction['transaction_id'], [
                'transaction_type' => $purchase['purchase_type'],
                'transaction_date' => $purchase['order_date'],
                'category' => 'Livestock sale',
                'description' => "Livestock sold (" . implode(',', $item_names).")",

                'entity_name' => $purchase['po_number'],
                'amount' => $purchase['total_amount'],
                'payment_method' => 'Purchase Order',
                'status' => $purchase['status'] === 'Received' ? 'Cleared' : 'Pending'
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }

}
