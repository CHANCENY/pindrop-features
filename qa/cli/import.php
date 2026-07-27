<?php

use Simp\Pindrop\Modules\qa\src\Services\ImportService;

return [
    'qa:import' => 'qaImportCommand',
];

/**
 * ./pindro qa:import <path/to/file.json> [imported_by_user_id] [imported_by_username]
 *
 * Shares ImportService with the admin UI upload form
 * (ModerationController::import()) — identical validation and behavior
 * either way. No logged-in user exists in CLI context, so
 * DatabasePermissionGuard's "no current user = system context" bypass
 * applies automatically; no auth setup needed to run this.
 */
function qaImportCommand(CLIPrinter $printer, ...$values): void
{
    $argv = $values[1] ?? [];
    $filePath = $argv[2] ?? null;
    $importedByUserId = isset($argv[3]) ? (int) $argv[3] : 0;
    $importedByUsername = $argv[4] ?? 'Import Bot';

    if (empty($filePath)) {
        $printer->printLine('Usage: ./pindro qa:import <path/to/file.json> [user_id] [username]', 'red');
        return;
    }

    if (!file_exists($filePath)) {
        $printer->printLine("Error: file not found: {$filePath}", 'red');
        return;
    }

    $json = file_get_contents($filePath);
    if ($json === false) {
        $printer->printLine("Error: could not read file: {$filePath}", 'red');
        return;
    }

    /** @var ImportService $importService */
    $importService = \getAppContainer()->get('qa.import');

    $printer->printLine('Importing questions from ' . $filePath . ' …', 'green');

    try {
        $result = $importService->importFromJson($json, $importedByUserId, $importedByUsername);
    } catch (\JsonException $e) {
        $printer->printLine('Invalid JSON: ' . $e->getMessage(), 'red');
        return;
    } catch (\InvalidArgumentException $e) {
        $printer->printLine($e->getMessage(), 'red');
        return;
    }

    $printer->printLine(
        "Done. Imported {$result['imported']} question(s), {$result['answers_imported']} answer(s), skipped {$result['skipped']}.",
        'green'
    );

    foreach ($result['errors'] as $error) {
        $printer->printLine('  - ' . $error, 'yellow');
    }
}
