<?php

namespace Simp\Pindrop\Modules\admin\src\Services;

use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Logger\LoggerInterface;
use Simp\Pindrop\Plugin\PluginManager;

class AutoCompleteService
{
    protected array $results = [];
    protected string $query = '';
    protected array $config = [];

    public function __construct(protected DatabaseService $database,
                                protected LoggerInterface $logger,
                                protected PluginManager $pluginManager
    )
    {
    }

    /**
     * @throws Exception
     */
    public function setConfig(array $config): static
    {
        $required = ['source', 'limit', 'sort'];
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new Exception("Missing required config key: {$key}");
            }
        }
        $this->config = $config;
        return $this;
    }

    /**
     * Get autocomplete results
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Exception
     */
    public function matches(string $query): array
    {
        $autoCompletes = $this->pluginManager->getPluginsYamlContent('autocomplete');

        $autoCompletesListHandler = [];

        $autoCompletes = array_values($autoCompletes);

        foreach ($autoCompletes as $autoComplete) {
            foreach ($autoComplete as $autoCompleteName => $autoCompleteConfig) {
                $autoCompletesListHandler[$autoCompleteName] = $autoCompleteConfig['handler'];
            }
        }

        if (empty($autoCompletesListHandler)) return [];

        $handler = $autoCompletesListHandler[$this->config['source']] ?? null;

        if (empty($handler)) return [];

        $list = explode('::', $handler);

        if (!class_exists($list[0])) {
            throw new Exception("Class {$list[0]} not found");
        }

        $object = \getAppContainer()->get($list[0]);

        if (!method_exists($object, $list[1])) {
            throw new Exception("Method {$list[1]} not found in {$list[0]}");
        }

        $limit = $this->config['limit'] ?? 10;
        $sort = $this->config['sort'] ?? 'DESC';
        $sort_by = $this->config['sort_by'] ?? null;

        return $object->{$list[1]}($query, $limit, $sort, $sort_by);
    }
}