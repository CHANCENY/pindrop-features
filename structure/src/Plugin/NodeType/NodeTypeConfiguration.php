<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\NodeType;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Modules\structure\src\Plugin\Structure\StructureConfigurationHandler;
use Simp\Pindrop\Modules\structure\src\Services\StructureManager;

class NodeTypeConfiguration
{
    public function __construct(protected StructureManager $structure, protected StructureConfigurationHandler $structureConfigurationHandler) {

    }

    /**
     * @throws DatabaseException
     */
    public function create(string $type, array|string $name, array $configuration): ?int
    {
        if ($this->structure->getStructuresByType($type)) {
            $configuration['struct_type'] = $type;
            $configuration['component'] = is_string($name) ? [$name] : $name;

            return $this->structureConfigurationHandler->save($name, $configuration);
        }
        return null;
    }

    /**
     * @throws DatabaseException
     */
    public function createFormDisplay(string $type, array|string $name, array $configuration): ?int
    {
        $list = explode('.',$type);
        $type1 = end($list);
        if ($this->structure->getStructuresByType($type1)) {
            $configuration['struct_type'] = $type;
            $configuration['component'] = is_string($name) ? [$name] : $name;

            return $this->structureConfigurationHandler->save($name, $configuration);
        }
        return null;
    }

    public function createField(string $type, array|string $name, array $configuration): ?int
    {

        if ($this->structure->getFieldTypesByType($type)) {
            $configuration['struct_type'] = $type;
            $configuration['component'] = is_string($name) ? [$name] : $name;
            return $this->structureConfigurationHandler->save($name, $configuration);
        }
        return null;
    }

    /**
     * @throws DatabaseException
     */
    public function delete(string $type, array|string $name): ?bool
    {
        if ($this->structure->getStructuresByType($type)) {
            return $this->structureConfigurationHandler->delete($name);
        }
        return null;
    }

    public function deleteField(string $type, array|string $name): ?bool {
        if ($this->structure->getFieldTypesByType($type)) {
            return $this->structureConfigurationHandler->delete($name);
        }
    }

    /**
     * @throws DatabaseException
     */
    public function getConfiguration(array|string $name)
    {
        return $this->structureConfigurationHandler->read($name);
    }

    public function getConfigurations(): array
    {
        return $this->structureConfigurationHandler->getConfigs();
    }

    /**
     * @throws DatabaseException
     */
    public function getFieldsByConfigType(array|string $component): array
    {
        $name = is_array($component) ? implode('.', $component) : $component;
        $fieldsType = $this->structure->getFieldTypes();
        $fieldsKeys = array_keys($fieldsType);
        $fieldsList = $this->structureConfigurationHandler->getFields($fieldsKeys);
        return array_filter(array_map(function ($field) use ($name) {
            return $name === $field['config']['entity_type'] ? $field : null;
        }, $fieldsList));
    }

    public function getContentTypes()
    {
        $types = $this->structureConfigurationHandler->getConfigs();
        return array_filter($types, function ($type) {
            return $type['config_type'] === 'node';
        });
    }

    /**
     * @throws DatabaseException
     */
    public function getContentType(array|string $component): array
    {
        // Get type config
        $configType = $this->getConfiguration($component);

        // Get the form display
        $formDisplaySetting = $this->getConfiguration([
            'node',
            'form',
            'display',
            $configType['config']['machine_name'],
            'settings'
        ]);

        $fields = $this->getFieldsByConfigType(['node', $configType['config']['machine_name']]);
        $fieldValidated = [];
        $weights = $formDisplaySetting['config']['weight'] ?? [];
        asort($weights);

        foreach ($weights as $key=>$weight) {
            foreach ($fields as $field) {
                if ($field['config']['name'] === $key) {

                    $fieldValidated[$key] = $field['config'];
                }
            }
        }

        return [
            ...$configType,
            'fields'  =>  $fieldValidated,
            'formDisplay' =>  $formDisplaySetting,
        ];
    }
}