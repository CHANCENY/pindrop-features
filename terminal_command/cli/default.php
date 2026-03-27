<?php

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\Database;
use Simp\Pindrop\Plugin\PluginManager;
use Symfony\Component\Yaml\Yaml;

$printer = new \CLIPrinter();

return [
    'site'      => 'siteInformation',
    'site:name' => 'siteInformGet',
    'env'       => 'systemEnvironment',
    'db:test'   => 'dbTest',
    'db:schema:create' => 'dbSchemaCreate',
    'plugins'   => 'getPlugins',
    'plugin:install' => 'installPlugin',
    'plugin:uninstall' => 'uninstallPlugin',
    'plugin:enable' => 'enablePlugin',
    'plugin:disable' => 'disablePlugin',
    'plugin:download' => 'downloadPlugin',
    'plugin:remove' => 'removePlugin',
    'plugin:create' => 'createPlugin',
];


/**
 * @throws DependencyException
 * @throws NotFoundException
 */
function siteInformation(CLIPrinter $printer, ...$values): void
{
    $container = \getAppContainer();

    $printer->printLine($container->get("SITE_NAME"), GREEN);
    $printer->printLine($container->get("SITE_URL"), GREEN);
    $printer->printLine($container->get("SITE_DESCRIPTION"), GREEN);
    $printer->printLine($container->get("SITE_AUTHOR"), GREEN);
    $printer->printLine($container->get("SITE_AUTHOR_URL"), GREEN);
    $printer->printLine($container->get("SITE_LICENSE"), GREEN);
    $printer->printLine($container->get("SITE_KEYWORDS"), GREEN);

}

function siteInformGet(CLIPrinter $printer, ...$values): void
{
    $name = $values[0];
    $list = explode(":", $name);
    $name = end($list);

    $sites = [
        'name' => 'SITE_NAME',
        'description' => 'SITE_DESCRIPTION',
        'author' => 'SITE_AUTHOR',
        'author_url' => 'SITE_AUTHOR_URL',
        'license' => 'SITE_LICENSE',
        'license_url' => 'SITE_LICENSE_URL',
        'url' => 'SITE_URL',
        'keywords' => 'SITE_KEYWORDS',
    ];

    $name = $sites[$name] ?? null;

    if (empty($name)) {
        $printer->printLine("No site information found.", RED);
        return;
    }

    $content = \getAppContainer()->has($name) ? \getAppContainer()->get($name) : "";
    $printer->printLine($content, GREEN);
}

function systemEnvironment(CLIPrinter $printer, ...$values): void
{

    $rows = [['KEY', 'VALUE']];

    foreach ($_ENV as $key=>$value) {
        $rows[] = [$key, $value];
    }

    $printer->printTable($rows, [GREEN,GREEN]);
}

function dbTest(CLIPrinter $printer, ...$values): void
{
    try{
        /** @var Database $database **/
        $database = \getAppContainer()->get('database');
        $printer->printLine("Connection: ". $database->getPdo() instanceof PDO, GREEN);

    }catch (Throwable $exception){
        $printer->printLine($exception->getMessage(), RED);
    }
}

function dbSchemaCreate(CLIPrinter $printer, ...$values): void
{

    /**@var Simp\Pindrop\Mysql\SchemaHandler $schemaHandler**/
    $schemaHandler = getAppContainer()->get('schema.handler');
    $tables = $schemaHandler->getSchemaInfo()['schema_files'] ?? [];
    $tables[-1] = "ALL";

    $selectedTable = $printer->askChoice("Which schema do you wish to create?", $tables, -1);

    if ($selectedTable === "ALL") {
        $returns  =  $schemaHandler->createTables();
        $results = [ ["FILE", "STATUS"] ];
        foreach ($returns as $return) {
            $results[] = [ $return['table'], ($return['success'] === true) ? 'created' : 'failed' ];
        }
        $printer->printTable($results);
        return;
    }

    $filename = pathinfo($selectedTable, PATHINFO_FILENAME);
    $returns = $schemaHandler->createTables([$filename]);
    $results = [ ["FILE", "STATUS"] ];
    foreach ($returns as $return) {
        $results[] = [ $return['table'], ($return['success'] === true) ? 'created' : 'failed' ];
    }
    $printer->printTable($results);
}

function getPlugins(CLIPrinter $printer, ...$values): void
{
    /**@var PluginManager $pluginManager **/
    $pluginManager = \getAppContainer()->get('plugin.manager');

    foreach ($pluginManager->getPlugins() as $plugin) {

        $line = "NAME:{$plugin['id']}\nINSTALLED:{$plugin['installed']}\nENABLED:{$plugin['enabled']}\nLOCATION:{$plugin['path']}\nINFO PATH:{$plugin['info_file']}\n";

        $printer->printLine(str_repeat("_",100), YELLOW);
        $printer->printLine(trim($line));
        $printer->printLine(str_repeat("_",100), YELLOW);

    }
}

function installPlugin(CLIPrinter $printer, ...$values): void
{
    $plugin_id = $values[1][2] ?? null;

    if (empty($plugin_id)) {
        $printer->printLine("Plugin name not given please run like ./pindro plugin:install <plugin_id>", RED);
        return;
    }

    /**@var PluginManager $pluginManager **/
    $pluginManager = \getAppContainer()->get('plugin.manager');

    $plugins = $pluginManager->getPlugins();

    if (empty($plugins[$plugin_id])) {
        $printer->printLine("Plugin not found: " . $plugin_id, RED);
        return;
    }

    if ($pluginManager->isPluginInstalled($plugin_id)) {
        $printer->printLine("Plugin already installed: " . $plugin_id, YELLOW);
        return;
    }

    if ($pluginManager->installPlugin($plugin_id)) {
        $printer->printLine("Plugin installed: " . $plugin_id, GREEN);
        return;
    }

    $printer->printLine("Plugin not installed: " . $plugin_id, RED);
    return;
}

function uninstallPlugin(CLIPrinter $printer, ...$values): void
{
    $plugin_id = $values[1][2] ?? null;

    if (empty($plugin_id)) {
        $printer->printLine("Plugin name not given please run like ./pindro plugin:uninstall <plugin_id>", RED);
        return;
    }

    /**@var PluginManager $pluginManager **/
    $pluginManager = \getAppContainer()->get('plugin.manager');

    $plugins = $pluginManager->getPlugins();

    if (empty($plugins[$plugin_id])) {
        $printer->printLine("Plugin not found: " . $plugin_id, RED);
        return;
    }

    if (!$pluginManager->isPluginInstalled($plugin_id)) {
        $printer->printLine("Plugin already uninstalled: " . $plugin_id, YELLOW);
        return;
    }

    if ($pluginManager->uninstallPlugin($plugin_id)) {
        $printer->printLine("Plugin uninstalled: " . $plugin_id, GREEN);
        return;
    }

    $printer->printLine("Plugin not uninstalled: " . $plugin_id, RED);
    return;
}

function enablePlugin(CLIPrinter $printer, ...$values): void
{
    $plugin_id = $values[1][2] ?? null;

    if (empty($plugin_id)) {
        $printer->printLine("Plugin name not given please run like ./pindro plugin:enable <plugin_id>", RED);
        return;
    }

    /**@var PluginManager $pluginManager **/
    $pluginManager = \getAppContainer()->get('plugin.manager');

    $plugins = $pluginManager->getPlugins();

    if (empty($plugins[$plugin_id])) {
        $printer->printLine("Plugin not found: " . $plugin_id, RED);
        return;
    }

    if (!$pluginManager->isPluginInstalled($plugin_id)) {
        $printer->printLine("Plugin not installed yet" . $plugin_id, YELLOW);
        return;
    }

    if ($pluginManager->enablePlugin($plugin_id)) {
        $printer->printLine("Plugin enabled: " . $plugin_id, GREEN);
        return;
    }

    $printer->printLine("Plugin not enabled: " . $plugin_id, RED);
    return;
}

function disablePlugin(CLIPrinter $printer, ...$values): void
{
    $plugin_id = $values[1][2] ?? null;

    if (empty($plugin_id)) {
        $printer->printLine("Plugin name not given please run like ./pindro plugin:disable <plugin_id>", RED);
        return;
    }

    /**@var PluginManager $pluginManager **/
    $pluginManager = \getAppContainer()->get('plugin.manager');

    $plugins = $pluginManager->getPlugins();

    if (empty($plugins[$plugin_id])) {
        $printer->printLine("Plugin not found: " . $plugin_id, RED);
        return;
    }

    if (!$pluginManager->isPluginInstalled($plugin_id)) {
        $printer->printLine("Plugin not installed yet" . $plugin_id, YELLOW);
        return;
    }

    if ($pluginManager->disablePlugin($plugin_id)) {
        $printer->printLine("Plugin disabled: " . $plugin_id, GREEN);
        return;
    }

    $printer->printLine("Plugin not disabled: " . $plugin_id, RED);
    return;
}

function downloadPlugin(CLIPrinter $printer, ...$values): void
{
    $plugin_id = $values[1][2] ?? null;

    if (empty($plugin_id)) {
        $printer->printLine("Plugin name not given please run like ./pindro plugin:download <plugin_id>", RED);
        return;
    }

    /**@var PluginManager $pluginManager **/
    $pluginManager = \getAppContainer()->get('plugin.manager');

    $plugins = $pluginManager->getPlugins();

    if (empty($plugins[$plugin_id])) {


        $printer->printLine("Downloading..." . $plugin_id, GREEN);
        $gitBinary = exec("which git");
        $downloadDir = __DIR__ . DIRECTORY_SEPARATOR . "downloads";

        if (!is_dir($downloadDir)) {
            mkdir($downloadDir, 0777, true);
        }
        if (!is_writable($downloadDir)) {
            chmod($downloadDir, 0777);
        }

        if (str_ends_with($gitBinary, "git")) {
            $command = "{$gitBinary} clone -b {$plugin_id} --single-branch https://github.com/CHANCENY/pindrop-features.git {$downloadDir}";
            $finished = exec($command, $output, $exitCode);

            if ($exitCode === 0) {
                $pluginsPath = $_ENV['PLUGIN_ROOT'];
                if (!is_dir($pluginsPath)) {
                    mkdir($pluginsPath, 0777, true);
                }

                $pluginPath = $pluginsPath . DIRECTORY_SEPARATOR . $plugin_id;
                if (rename($downloadDir.DIRECTORY_SEPARATOR.$plugin_id, $pluginPath)) {
                    $printer->printLine("Plugin downloaded: " . $plugin_id, GREEN);
                }
                else {
                    $printer->printLine("Failed to movie plugin: " . $plugin_id. " from ".$downloadDir, RED);
                }

                deleteDirectory($downloadDir);
            }
        }
        else {
            $printer->printLine("Git binary not found", RED);
        }

        return;
    }

}

function deleteDirectory(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }

    $files = scandir($dir);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $file;

        if (is_dir($path)) {
            deleteDirectory($path); // recursive call
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}

function removePlugin(CLIPrinter $printer, ...$values): void
{
    $plugins = array_slice($values[1],2, count($values[1]));
    if (empty($plugins)) {
        $printer->printLine("No plugin given", RED);
        return;
    }

    /**@var PluginManager $pluginManager **/
    $pluginManager = \getAppContainer()->get('plugin.manager');

    foreach ($plugins as $plugin) {

        if ($pluginManager->isPluginInstalled($plugin)) {
            if ($pluginManager->uninstallPlugin($plugin)) {
                $printer->printLine("Plugin uninstalled: " . $plugin, GREEN);
            }
        }
    }

    foreach ($plugins as $plugin) {
        if (!$pluginManager->isPluginInstalled($plugin)) {
            $plugin_data = $pluginManager->getPlugin($plugin);
            if (is_array($plugin_data) && isset($plugin_data['path'])) {
                $plugin_path = $plugin_data['path'];
                if (deleteDirectory($plugin_path)) {
                    $printer->printLine("Plugin removed: " . $plugin, GREEN);
                }
            }
        }
    }

    $printer->printLine(PHP_EOL.PHP_EOL, GREEN);
    $printer->printLine("Finished removing plugin (s)", GREEN);
}

function createPlugin(CLIPrinter $printer, ...$values): void
{
    /**@var PluginManager $pluginManager **/
    $pluginManager = \getAppContainer()->get('plugin.manager');

    function createPluginId(string $name): string
    {
        // Trim and lowercase
        $name = strtolower(trim($name));

        // Remove everything except letters and spaces
        $name = preg_replace('/[^a-z\s]/', '', $name);

        // Replace spaces with underscore
        $name = preg_replace('/\s+/', '_', $name);

        // Replace multiple underscores with single
        $name = preg_replace('/_+/', '_', $name);

        // Trim underscores from start/end
        return trim($name, '_');
    }

    $pluginName = $printer->ask("Enter the name of the plugin you wish to create", "",function ($answer) use ($pluginManager, $printer)  {
        $id = createPluginId($answer);
        if (!empty($pluginManager->getPlugin($id))) {
            $printer->printLine("Plugin already exists: " . $id, RED);
            return false;
        }
        return true;
    });

    $description = $printer->ask("Enter the description of the plugin", "");
    $version = $printer->ask("Enter the version of the plugin", "1.0.0");
    $author = $printer->ask("Enter the author of the plugin", "");
    $website = $printer->ask("Enter author website of the plugin", "");
    $license = $printer->ask("Enter plugin license:", "");

    $info = [
        "name" => $pluginName,
        "description" => $description,
        "version" => $version,
        "author" => $author,
        "website" => $website,
        "license" => $license
    ];

    $pluginPath = $_ENV['PLUGIN_ROOT'] . DIRECTORY_SEPARATOR . createPluginId($pluginName);
    if (is_dir($pluginPath)) {
        $printer->printLine("Plugin already exists: " . $pluginPath, RED);
        return;
    }

    mkdir($pluginPath, 0777, true);

    $infoFile = $pluginPath . DIRECTORY_SEPARATOR . "info.yml";
    if (file_put_contents($infoFile, YAML::dump($info,YAML::DUMP_MULTI_LINE_LITERAL_BLOCK))) {
        $printer->printLine("Plugin created: " . $infoFile, GREEN);

        $files[] = $pluginPath . DIRECTORY_SEPARATOR . "entity.repository.yml";
        $files[] = $pluginPath . DIRECTORY_SEPARATOR . "autocomplete.yml";
        $files[] = $pluginPath . DIRECTORY_SEPARATOR . "menu.yml";
        $files[] = $pluginPath . DIRECTORY_SEPARATOR . "middleware.yml";
        $files[] = $pluginPath . DIRECTORY_SEPARATOR . "routing.yml";
        $files[] = $pluginPath . DIRECTORY_SEPARATOR . "services.yml";
        $files[] = $pluginPath . DIRECTORY_SEPARATOR . "settings.config.yml";
        $files[] = $pluginPath . DIRECTORY_SEPARATOR . "pindro.commands.yml";

        foreach ($files as $file) {
            touch($file);
        }
        $printer->printLine("Finished creating plugin.", GREEN);
    }

}