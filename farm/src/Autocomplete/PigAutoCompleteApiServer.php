<?php

namespace Simp\Pindrop\Modules\farm\src\Autocomplete;

use DateTime;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\farm\src\Services\Barn;
use Simp\Pindrop\Modules\farm\src\Services\Facility;
use Simp\Pindrop\Modules\farm\src\Services\Pen;
use Simp\Pindrop\Modules\farm\src\Services\Pig;

class PigAutoCompleteApiServer
{
    protected Pig $pig_service;
    protected Facility $facility_service;
    protected Barn $barn_service;
    protected Pen $pen_service;

    public function __construct(protected DatabaseService $database)
    {
        $this->pig_service = getAppContainer()->get('farm.pig');
        $this->facility_service = getAppContainer()->get('farm.facility');
        $this->barn_service = getAppContainer()->get('farm.barn');
        $this->pen_service = getAppContainer()->get('farm.pen');
    }

    public function matchMalePigs(string $query, int $limit = 10, $sort = 'DESC', $sort_by = "created_at"): array
    {
        if (empty($limit)) {
            $limit = 10;
        }

        if (empty($sort)) {
            $sort = "DESC";
        }
        if (empty($sort_by)) {
            $sort_by = "created_at";
        }

        $pigs = $this->pig_service->getSirePigsByTag($query, $limit, $sort, $sort_by);

        return array_map(function ($result) {
            return [
                'value' => $result['pig_id'],
                'label' => $result['pig_id']
            ];
        }, $pigs);

    }

    public function matchFemalePigs(string $query, int $limit = 10, $sort = 'DESC', $sort_by = "created_at"): array
    {
        if (empty($limit)) {
            $limit = 10;
        }

        if (empty($sort)) {
            $sort = "DESC";
        }
        if (empty($sort_by)) {
            $sort_by = "created_at";
        }

        $pigs = $this->pig_service->getDamPigsByTag($query, $limit, $sort, $sort_by);
       
        return array_map(function ($result) {
            return [
                'value' => $result['pig_id'],
                'label' => $result['pig_id']
            ];
        }, $pigs);

    }

    public function generatePigLabelText(string $query, int $limit = 10, $sort = 'DESC', $sort_by = "created_at")
    {
        $tagCounter = $this->database->table('pig_tags_counter')->limit(1)->first();
        $number = 1;
        $breeds = [
            'Landrace',
            'Yorkshire',
            'Duroc',
            'Hampshire',
            'Berkshire',
            'Mixed'
        ];

        $breed = "";
        foreach ($breeds as $b) {
            if (str_starts_with(strtolower($b), $query)) {
                $breed = $b;
                break;
            }
        }

        if ($tagCounter) {
            $number = $tagCounter['unit'] + 1;
            $this->database->table('pig_tags_counter')->where('id', '=', $tagCounter['id'])->update(['unit' => $number]);
        }
        else {
            $this->database->table('pig_tags_counter')->insert([
                'unit'=>$number
            ]);
        }

        $line = strtoupper("PIG-$breed-$number");
        return [
            [
                'value' => $line,
                'label' => $line
            ]
        ];
    }

    public function getLocationsBarnAndPens(string $query, int $limit = 10, $sort = 'DESC', $sort_by = "created_at")
    {
        $barns = $this->barn_service->getBarnsByFacilityId($query);
        $barnsSlashPerns = [];
        foreach ($barns as $barn) {
            $perns = $this->pen_service->getPensByBarnId($barn['barn_id']);
            foreach ($perns as $pern) {
                if ($pern['current_load'] < $pern['capacity']) {
                    $barnsSlashPerns[] = [
                        'value' => "{$barn['name']} / {$pern['name']}",
                        'label' => "{$barn['name']} / {$pern['name']}"
                    ];
                }
            }
        }
        return $barnsSlashPerns;
    }

    public function getPigBatch(string $query, int $limit = 10, $sort = 'DESC', $sort_by = "created_at")
    {
        $pens = $this->pen_service->getPens($query) ?? [];
        $pigs = $this->pig_service->getPigById($query) ?? [];
        
        $results = [];
        foreach($pens as $pen){
            $barn = $this->barn_service->getBarnById($pen['barn_id']);
            if (!$barn) continue;

            $facility = $this->facility_service->getFacilityById($barn['facility_id']);

            if (!$facility) continue;

            $results[] = [
                'value' => "BATCH [{$facility['name']}, {$barn['name']}, {$pen['name']}] ({$pen['pen_id']})",
                'label' => "BATCH [{$facility['name']}, {$barn['name']}, {$pen['name']}] ({$pen['pen_id']})"
            ];
        }

       
        foreach([$pigs] as $pig) {
           
           if (empty($pig)) continue;

            $results[] = [
                'value'  => $pig['pig_id'],
                'label'  => $pig['pig_id']
            ];
           
        }

        return $results;
    }

    public function getPurchaseOrderNumber(string $query, int $limit = 10, $sort = 'DESC', $sort_by = "created_at")
    {
        $now = new DateTime('now');
    
        $po_number = "PO-".$now->format('Y')."-".time();
        return [[
            'value' => $po_number,
            'label' => $po_number
        ]];
    }

}
