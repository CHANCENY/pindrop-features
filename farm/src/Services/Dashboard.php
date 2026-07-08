<?php

namespace Simp\Pindrop\Modules\farm\src\Services;

use DateTime;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;

class Dashboard
{
    public function __construct(protected DatabaseService $database_service, protected LoggerInterface $logger_interface){}

    public function dashboard(): array {

         $now = new DateTime('now'); 
         $endDate =(clone $now)->modify("last day of this month")->format('Y-m-d');
         $startDate = (clone $now)->modify('first day of this month')->format('Y-m-d');

         $facilities = $this->database_service->table('facilities')->get();
         $list = [];
         foreach($facilities as $facility) {
            $total = $facility['current_load'] ?? 0;

            $list[$facility['name']] = $total == 0 ? 0 : ($total / $facility['capacity']) * 100;
         } 
         
         $statics = [
            'pigs_total' => $this->database_service->table('pigs')->count(),
            'pregnant_total' => $this->database_service->table('inseminations')->whereIn('status', ['Imminent','Confirmed'])->count(),
            'feed_stock'     => $this->database_service->table('feed_silos')->select(["SUM(current_level_pct) AS total"])->first()['total'] ?? 0,
            'monthly_income' => $this->database_service->table('transactions')->whereBetween('transaction_date',$startDate, $endDate)
                              ->where('transaction_type', '=', 'Income')->where('status', '=', 'Cleared')->select(["SUM(amount) AS total_amount"])->first()['total_amount'],
            'farms' => $list                  
         ];
        return $statics;
    }
}
