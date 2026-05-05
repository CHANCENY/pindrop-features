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
        return $this->db->fetchAll("SELECT * FROM pig_farmer_feeding_logs WHERE pig_id = ? ORDER BY feed_date DESC", ...$i=[$pigId]);
    }

    public function addFeedingLog(array $data): bool
    {
        $query = "INSERT INTO pig_farmer_feeding_logs (pig_id, feed_type, quantity, feed_date) VALUES (?, ?, ?, ?)";
        return $this->db->query($query, ...$i=[
            $data["pig_id"],
            $data["feed_type"],
            $data["quantity"],
            $data["feed_date"]
        ])->rowCount() > 0;
    }

     public function getFeedingDataForChart(): array
    {
        return $this->db->fetchAll("
           SELECT feed_date, SUM(quantity) / COUNT(DISTINCT pig_id) as total_quantity
FROM pig_farmer_feeding_logs
GROUP BY feed_date
ORDER BY feed_date ASC
LIMIT 30
        ");
    }
}
