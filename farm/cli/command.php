<?php

use Simp\Pindrop\Modules\farm\src\Services\Pig;
use Simp\Pindrop\Modules\farm\src\Services\Transaction;
use Simp\Pindrop\Modules\farm\src\Services\Treatment;

return [
    'farm:import-finance' => 'importFinance',
    'farm:import-health' => 'importHealth'
];


function importFinance(\CLIPrinter $cLIPrinter, ...$values)
{

    $cLIPrinter->printLine("Paste Your Json Text Below then press ctrl D", GREEN);

    $json = stream_get_contents(STDIN);

    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $cLIPrinter->printLine("Invalid JSON: " . json_last_error_msg(), RED);
        return;
    }

    $finances = $data[2]['data'] ?? [];

    /**
     * @var Transaction $transaction
     */
    $transaction = getAppContainer()->get('farm.financial');

    foreach ($finances as $transaction_data) {



        $id = $transaction->addTransaction([
            'transaction_date' => $transaction_data['transaction_date'],
            'transaction_type' => $transaction_data['type'],
            'category' => $transaction_data['category'],
            'description' => $transaction_data['description'],
            'amount' => $transaction_data['amount'],
            'payment_method' => $transaction_data['Cash'],
            'status' => 'Paid',
            'entity_name' => 'OLD-' . $transaction_data['id']
        ]);

        $cLIPrinter->printLine("Created Transaction entry with ID: $id");
    }

    $cLIPrinter->printLine("Done All Imported", GREEN);

}

function importHealth(\CLIPrinter $cLIPrinter, ...$values)
{
    $cLIPrinter->printLine("Paste Your Json Text Below then press ctrl D", GREEN);

    $json = stream_get_contents(STDIN);

    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $cLIPrinter->printLine("Invalid JSON: " . json_last_error_msg(), RED);
        return;
    }

    $healths = $data[2]['data'] ?? [];

    /**
     * @var Treatment
     */
    $healthService = getAppContainer()->get('farm.health');

    /**
     * @var Pig
     */
    $pigService = getAppContainer()->get('farm.pig');
    $pigList = $pigService->getAllPigs();
    $pigIds = array_column($pigList, 'pig_id');

    foreach ($healths as $health) {
        foreach ($pigIds as $pig_id) {
            $id = $healthService->createTreatment([
                'pig_id'   =>  $pig_id,
                'diagnosis'  => $health['condition'],
                'treatment'  => $health['treatment'],
                'treatment_date'  => $health['checkup_date'],
                'attending_vet'   => "Mr Chawinga",
                'outcome'         => "Ongoing Monitoring",
                'notes'           => $health['notes'],
            ]);
            $cLIPrinter->printLine("Health ID: $id");
        }
    }
    $cLIPrinter->printLine("Done import");
}