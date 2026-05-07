<?php

/**
 * Command to handle system updates and ugrade.
 */

use Simp\Pindrop\System\System;

return [
    'system' => 'systemHelp',
    'system:latest-check' => 'checkLatestVersion',
    'system:version'      => 'checkSystemVersion',
    'system:download'     => 'downloadVersion',
];

function systemHelp(\CLIPrinter $printer, ...$values){
    $content = <<<HELP

    System command are mainly dealing with pindrop, modules/admin, module/terminal_command directories and composer.json file.

    The following are command system includes
    1. system:latest-check -This command check if there is latest version on github compare to existing.
    2. system:download <version> -<flag> -This command can download the version of release you need and by specified flag
                                          can update, download ie update, download are the flags if -update is given the command
                                          will download and automatically update the system and if -download the command will just 
                                          download without update.
    3. system:local-update  - This command will update the system using previous downloaded version
    4. system:assets:clear  - This command will wipe the assets table in database.
    5. system:version - This command will show the current version of system.

    HELP;
}

function systemObject(\CLIPrinter $printer): System {
    return new System(\getAppContainer()->get('database'));
}

function checkLatestVersion(\CLIPrinter $printer, ...$values){
    $version = systemObject($printer)->checkLatestVersion();
    $printer->printLine("Latest version: $version");
}

function checkSystemVersion(\CLIPrinter $printer, ...$values){
    $version = systemObject($printer)->checkSystemVersion();
    $printer->printLine("Current version: $version", GREEN);
}

function downloadVersion(\CLIPrinter $printer, ...$values)
{
    // pindrop-1.0.8
   
    $version = $values[1][2] ?? null;
    $flag = $values[1][3] ?? null;

    if (!$version) {
        $printer->printLine("Please specify the version to download. Usage: system:download <version> -<flag>", RED);
        return;
    }

    if (systemObject($printer)->downloadVersion($version)) {

        $printer->printLine("Version $version downloaded successfully.", GREEN);

        if ($flag === '-update') {
            // Here you can add code to automatically update the system using the downloaded version.
            $printer->printLine("System updated to version $version successfully.", GREEN);

            if (systemObject($printer)->updateSystem($version)) {
                $current_version = systemObject($printer)->checkSystemVersion();
                systemObject($printer)->changeVersion($version);
                $printer->printLine("System updated from version $current_version to version $version successfully.", GREEN);
                
            } else {
                $printer->printLine("Failed to update system to version $version. Please try updating manually.", RED);
            }
        }
        
    } else {
        $printer->printLine("Failed to download version $version. Please check the version and try again.", RED);

    }
}