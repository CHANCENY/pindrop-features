<?php


use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Modules\structure\src\Plugin\Structure\StructureConfigurationHandler;

return [
    'st:export' => "configExport",
    'st:import' => "configImport",
];

/**
 * @throws DatabaseException
 * @throws DependencyException
 * @throws NotFoundException
 */
function configExport(CLIPrinter $printer, ...$values)
{
    /**@var StructureConfigurationHandler $configHandler **/
    $configHandler = \getAppContainer()->get("structure.config");
    $configs = $configHandler->getConfigs();

    $rows[] = ["CONFIG NAME", "ACTION"];

    foreach ($configs as $config) {
        $rows[] = [
            $config["name"],
            !empty($config['deleted']) ? "\033[0;31mDelete\033[0m" : "\033[0;32mModify\033[0m"
        ];
    }
    $printer->printTable($rows);

    $input = $printer->askChoice("Are you sure you want to export?", ['Yes', 'No'],0);

    if ($input === "Yes") {
        $results = $configHandler->export();
        $printer->printLines($results);
        $printer->printLine("Finished export.", "green");
    }
    else {
        $printer->printLine("Export cancelled", "red");
    }

}

function configImport(CLIPrinter $printer, ...$values) {

    /**@var StructureConfigurationHandler $configHandler **/
    $configHandler = \getAppContainer()->get("structure.config");
    $configs = $configHandler->getConfigs(false);

    $rows[] = ["CONFIG NAME", "ACTION"];

    foreach ($configs as $config) {
        $rows[] = [
            $config["name"],
            !empty($config['deleted']) ? "\033[0;31mDelete\033[0m" : "\033[0;32mModify\033[0m"
        ];
    }
    $printer->printTable($rows);

    $input = $printer->askChoice("Are you sure you want to import?", ['Yes', 'No'],0);

    if ($input === "Yes") {
        $results = $configHandler->import();
        $printer->printLines($results);
        $printer->printLine("Finished import.", "green");
    }
    else {
        $printer->printLine("Import cancelled", "red");
    }

}