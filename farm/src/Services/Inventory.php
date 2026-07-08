<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Inventory
{
    protected string $inventory_item = 'inventory_items';

    protected array $status = ['In Stock','Low Stock','Out of Stock'];

    public function __construct(protected DatabaseService $database_service, protected LoggerInterface $logger_interface){}

    public function addInventory(array $data): ?int {
        if (isset($data['status']) && !in_array($data['status'], $this->status)){
            return null;
        }

        return $this->database_service->table($this->inventory_item)->insert($data);
    }

    public function getAllInventory(): array {
        return $this->database_service->table($this->inventory_item)->get();
    }

    public function getInventory(int $id): ?array {
        return $this->database_service->table($this->inventory_item)->where('item_id','=', $id)->first();
    }

    public function updateInventory(int $id, array $data): bool {
        return $this->database_service->table($this->inventory_item)->where('item_id', '=', $id)->update($data);
    }

    public function deleteInventory(int $id): bool {
         return $this->database_service->table($this->inventory_item)->where('item_id', '=', $id)->delete( );
    }

}
