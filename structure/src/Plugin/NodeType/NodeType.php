<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\NodeType;

use Simp\Pindrop\Modules\structure\src\Plugin\Structure\StructureInterface;
use Simp\Pindrop\Routing\Url;

class NodeType implements StructureInterface
{

    public function getName(): string
    {
        return 'Content types';
    }

    public function getDescription(): string
    {
        return "Node structure provide the ability to organise contents";
    }

    public function getType(): string
    {
        return "node";
    }

    public function getUrl(): string
    {
        return Url::routeByName('structure.structures.content.types');
    }
}