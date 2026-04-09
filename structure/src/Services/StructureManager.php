<?php

namespace Simp\Pindrop\Modules\structure\src\Services;

use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Structure\StructureInterface;
use Simp\Pindrop\Plugin\PluginManager;

class StructureManager
{
    protected PluginManager $manager;

    protected array $structures = [];
    protected array $fieldTypes = [];

    public function __construct(PluginManager $manager) {
        $this->manager = $manager;

        $structures = $this->manager->getPluginsYamlContent('structures');
        foreach ($structures as $structure) {
            foreach ($structure as $key=>$structureItem) {
                if (is_array($structureItem) && !empty($structureItem['class']) && !empty($structureItem['status'])) {
                    $this->structures[$key] = new $structureItem['class'];
                }
            }
        }
        $fieldTypes = $this->manager->getPluginsYamlContent('fields.types');
        foreach ($fieldTypes as $fieldType) {
            foreach ($fieldType as $key=>$fieldItem) {
                if (is_array($fieldItem) && !empty($fieldItem['class']) && !empty($fieldItem['status'])) {
                    $this->fieldTypes[$key] = new $fieldItem['class'];
                }
            }
        }
    }

    public function getStructures(): array {
        return $this->structures;
    }

    public function getStructuresByType(string $type): ?StructureInterface {
        return $this->structures[$type] ?? null;
    }

    public function getFieldTypes(): array
    {
        return $this->fieldTypes;
    }

    public function getFieldTypesByType(string $type): ?FieldTypeInterface
    {
        return $this->fieldTypes[$type] ?? null;
    }
}