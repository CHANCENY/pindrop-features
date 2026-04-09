<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\Structure;

use Simp\Pindrop\Database\Database;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Symfony\Component\Yaml\Yaml;

class StructureConfigurationHandler
{

    protected DatabaseService $database;

    public function __construct(DatabaseService $database) {
        $this->database = $database;
    }

    /**
     * @throws DatabaseException
     */
    public function save(array|string $configNameComponent, array $configuration): int
    {

        // write to storage by string
        $name = $configNameComponent;

        // build name from array if $configNameComponent is array
        if (is_array($configNameComponent)) {
            $name = implode('.', $configNameComponent);
        }

        $params['config'] = Yaml::dump($configuration);
        $params['name'] = $name;

        if ($this->isConfigExists($name)) {
            return $this->database->query("UPDATE structure_configuration SET config = :config WHERE name = :name", ...$params)->rowCount();
        }

        $params['config_type'] = $configuration['struct_type'] ?? "field";
        return $this->database->insert("structure_configuration", $params);
    }

    /**
     * @throws DatabaseException
     */
    public function isConfigExists(string $name): bool
    {
        $sql = "SELECT name FROM structure_configuration WHERE name = :name";
        $results = $this->database->query($sql, $name)?->fetch() ?? false;
        return $results !== false;
    }

    /**
     * @throws DatabaseException
     */
    public function read(array|string $name) {

        $name = is_string($name) ? $name : implode('.', $name);

        $result = $this->database->query("SELECT * FROM structure_configuration WHERE name = :name", $name)?->fetch() ?? false;
        if ($result) {
            $result['config'] = Yaml::parse($result['config']);
        }
        return $result;
    }

    /**
     * @throws DatabaseException
     */
    public function export(): array
    {
        $exportPath = $_ENV['CONFIG']. DIRECTORY_SEPARATOR . 'configs';
        if (!is_dir($exportPath)) {
            mkdir($exportPath, 0777, true);
        }
        $results = [];
        $configs = $this->database->query("SELECT * FROM structure_configuration")?->fetchAll() ?? false;
        if ($configs) {

            foreach ($configs as $config) {

                $filename = $config['name'] . '.yml';
                $fullPath = $exportPath . DIRECTORY_SEPARATOR . $filename;

                if (!empty($config['deleted'])) {
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                        $name = $config['name'];
                        $this->database->query("DELETE FROM structure_configuration WHERE name = :name", $name);
                    }
                }

                elseif (file_put_contents($fullPath, $config['config'])) {
                    $results[] = "[{$config['name']}] exported successfully";
                }
                else {
                    $results[] = "[{$config['name']}] failed to export";
                }
            }
        }
        return $results;
    }

    /**
     * @throws DatabaseException
     */
    public function import(): array {
        $importPath = $_ENV['CONFIG'] . DIRECTORY_SEPARATOR . 'configs';
        if (!is_dir($importPath)) {
            mkdir($importPath, 0777, true);
        }
        $results = [];

        $files = array_diff(scandir($importPath) ?? [], ['.', '..']);
        foreach ($files as $file) {
            $filename = $importPath . DIRECTORY_SEPARATOR . $file;
            if (file_exists($filename)) {
                $name = pathinfo($filename, PATHINFO_FILENAME);
                if ($this->save($name, Yaml::parseFile($filename))) {
                    $results[] = "[{$name}] imported successfully";
                }
                else {
                    $results[] = "[{$name}] failed to import";
                }
            }
        }
        return $results;
    }

    /**
     * @throws DatabaseException
     */
    public function delete(array|string $configNameComponent): false|int
    {
        // write to storage by string
        $name = $configNameComponent;

        // build name from array if $configNameComponent is array
        if (is_array($configNameComponent)) {
            $name = implode('.', $configNameComponent);
        }

        $sql = "UPDATE structure_configuration SET deleted = :deleted WHERE name = :name";
        $params = ['deleted' => 1, 'name' => $name];
        if ($this->isConfigExists($name)) {
            return $this->database->query($sql, ...$params)->rowCount();
        }
        return false;
    }

    /**
     * @throws DatabaseException
     */
    public function isDelete(array|string $configNameComponent): bool
    {
        // write to storage by string
        $name = $configNameComponent;

        // build name from array if $configNameComponent is arrayed
        if (is_array($configNameComponent)) {
            $name = implode('.', $configNameComponent);
        }

        $sql = "SELECT name FROM structure_configuration WHERE name = :name AND deleted = 1";
        $params = ['name' => $name];
        return !empty($this->database->query($sql, ...$params)?->fetch());
    }

    /**
     * @throws DatabaseException
     */
    public function getConfigs(bool $is_export = true): array
    {
        $sql = "SELECT * FROM structure_configuration";
        $results = $this->database->query($sql)?->fetchAll() ?? false;
        $results = array_map(function ($config) {
            $config['config'] = Yaml::parse($config['config']);
            return $config;
        },$results);
        $configPath = $_ENV['CONFIG'] . DIRECTORY_SEPARATOR . 'configs';

        if (!is_dir($configPath)) {
            mkdir($configPath, 0777, true);
        }

        $files = array_diff(scandir($configPath) ?? [], ['.', '..']);

        if (!$is_export) {

           $results = [];
           foreach ($files as $file) {
               $name = pathinfo($file, PATHINFO_FILENAME);

               $results[] = [
                   'name' => $name,
                   'deleted' => $this->isDelete($name),
                   'config'  => Yaml::parse(file_get_contents($configPath . DIRECTORY_SEPARATOR . $file))
               ];
           }
           return $results;

        }

        return $results;
    }

    /**
     * @throws DatabaseException
     */
    public function getFields(array $types)
    {
        if (empty($types)) {
            return [];
        }

        // Create placeholders (?, ?, ?)
        $placeholders = implode(',', array_fill(0, count($types), '?'));

        $sql = "SELECT * FROM structure_configuration WHERE config_type IN ($placeholders)";

        $results = $this->database->query($sql, ...$types)?->fetchAll() ?? [];

        return array_map(function ($field) {
            $field['config'] = Yaml::parse($field['config']);
            return $field;
        }, $results);
    }
}