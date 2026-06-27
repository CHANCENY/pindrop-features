<?php

namespace Simp\Pindrop\Modules\pig_farmer\src\Services;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class FeedingLogManager
{
    private DatabaseService $db;
    private $logger;

    public function __construct(DatabaseService $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * @throws DatabaseException
     */
    public function getFeedingLogsByPigId(int $pigId): array
    {
        return $this->db->table('pig_farmer_feeding_logs')
            ->where('pig_id', '=', $pigId)
            ->latest('feed_date')
            ->get();
    }

    /**
     * @throws DatabaseException
     */
    public function addFeedingLog(array $data): bool
    {
        $id = $this->db->table('pig_farmer_feeding_logs')->insert([
            'pig_id'    => $data['pig_id'],
            'feed_type' => $data['feed_type'],
            'quantity'  => $data['quantity'],
            'feed_date' => $data['feed_date'],
        ]);
        return $id > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function getFeedingDataForChart(): array
    {
        return $this->db->table('pig_farmer_feeding_logs')
            ->select(['feed_date', 'SUM(quantity) / COUNT(DISTINCT pig_id) as total_quantity'])
            ->groupBy('feed_date')
            ->orderBy('feed_date', 'ASC')
            ->limit(30)
            ->get();
    }
}
