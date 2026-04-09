<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\Structure;

interface StructureInterface
{
    public function getName(): string;

    public function getDescription(): string;

    public function getType(): string;

    public function getUrl(): string;

}