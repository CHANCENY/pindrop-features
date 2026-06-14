<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\Structure;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Symfony\Component\Yaml\Yaml;

class StructureConfigurationHandler
{
    public function __construct(protected DatabaseService $database)
    {
    }

    /**
     * @throws DatabaseException
     */
    public function save(array|string $configNameComponent, array $configuration): int
    {
        $name = is_array($configNameComponent)
            ? implode('.', $configNameComponent)
            : $configNameComponent;

        $config     = Yaml::dump($configuration);
        $configType = $configuration['struct_type'] ?? 'field';

        if ($this->isConfigExists($name)) {
            return $this->database->table('structure_configuration')
                ->where('name', '=', $name)
                ->update(['config' => $config]);
        }

        return $this->database->table('structure_configuration')->insert([
            'name'        => $name,
            'config'      => $config,
            'config_type' => $configType,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function isConfigExists(string $name): bool
    {
        return $this->database->table('structure_configuration')
            ->where('name', '=', $name)
            ->exists();
    }

    /**
     * @throws DatabaseException
     */
    public function read(array|string $name): array|false
    {
        $name   = is_string($name) ? $name : implode('.', $name);
        $result = $this->database->table('structure_configuration')
            ->where('name', '=', $name)
            ->first();

        if ($result) {
            $result['config'] = Yaml::parse($result['config']);
        }
        return $result ?: false;
    }

    /**
     * @throws DatabaseException
     */
    public function export(): array
    {
        $exportPath = ($_ENV['CONFIG'] ?? '') . DIRECTORY_SEPARATOR . 'configs';
        if (!is_dir($exportPath)) {
            mkdir($exportPath, 0777, true);
        }

        $results = [];
        $configs  = $this->database->table('structure_configuration')->get();

        foreach ($configs as $config) {
            $filename = $config['name'] . '.yml';
            $fullPath = $exportPath . DIRECTORY_SEPARATOR . $filename;

            if (!empty($config['deleted'])) {
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                    $this->database->table('structure_configuration')
                        ->where('name', '=', $config['name'])
                        ->delete();
                }
            } elseif (file_put_contents($fullPath, $config['config'])) {
                $results[] = "[{$config['name']}] exported successfully";
            } else {
                $results[] = "[{$config['name']}] failed to export";
            }
        }
        return $results;
    }

    /**
     * @throws DatabaseException
     */
    public function import(): array
    {
        $importPath = ($_ENV['CONFIG'] ?? '') . DIRECTORY_SEPARATOR . 'configs';
        if (!is_dir($importPath)) {
            mkdir($importPath, 0777, true);
        }

        $results = [];
        $files   = array_diff(scandir($importPath) ?: [], ['.', '..']);

        foreach ($files as $file) {
            $filename = $importPath . DIRECTORY_SEPARATOR . $file;
            if (file_exists($filename)) {
                $name = pathinfo($filename, PATHINFO_FILENAME);
                if ($this->save($name, Yaml::parseFile($filename))) {
                    $results[] = "[{$name}] imported successfully";
                } else {
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
        $name = is_array($configNameComponent)
            ? implode('.', $configNameComponent)
            : $configNameComponent;

        if (!$this->isConfigExists($name)) {
            return false;
        }

        return $this->database->table('structure_configuration')
            ->where('name', '=', $name)
            ->update(['deleted' => 1]);
    }

    /**
     * @throws DatabaseException
     */
    public function isDelete(array|string $configNameComponent): bool
    {
        $name = is_array($configNameComponent)
            ? implode('.', $configNameComponent)
            : $configNameComponent;

        return $this->database->table('structure_configuration')
            ->where('name', '=', $name)
            ->where('deleted', '=', 1)
            ->exists();
    }

    /**
     * @throws DatabaseException
     */
    public function getConfigs(bool $is_export = true): array
    {
        if (!$is_export) {
            $configPath = ($_ENV['CONFIG'] ?? '') . DIRECTORY_SEPARATOR . 'configs';
            if (!is_dir($configPath)) {
                mkdir($configPath, 0777, true);
            }
            $files   = array_diff(scandir($configPath) ?: [], ['.', '..']);
            $results = [];
            foreach ($files as $file) {
                $name      = pathinfo($file, PATHINFO_FILENAME);
                $results[] = [
                    'name'    => $name,
                    'deleted' => $this->isDelete($name),
                    'config'  => Yaml::parse(
                        file_get_contents($configPath . DIRECTORY_SEPARATOR . $file)
                    ),
                ];
            }
            return $results;
        }

        $rows = $this->database->table('structure_configuration')->get();
        return array_map(function ($config) {
            $config['config'] = Yaml::parse($config['config']);
            return $config;
        }, $rows);
    }

    /**
     * @throws DatabaseException
     */
    public function getFields(array $types): array
    {
        if (empty($types)) {
            return [];
        }

        $rows = $this->database->table('structure_configuration')
            ->whereIn('config_type', $types)
            ->get();

        return array_map(function ($field) {
            $field['config'] = Yaml::parse($field['config']);
            return $field;
        }, $rows);
    }
}
