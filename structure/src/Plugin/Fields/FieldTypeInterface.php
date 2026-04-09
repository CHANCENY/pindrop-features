<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\Fields;

use Twig\Markup;

interface FieldTypeInterface
{
    public function getType(): string;

    public function svgIcon(): Markup;

    public function getLabel(): string;

    public function getDescription(): string;

    public function getWidget(): FieldTypeWidgetInterface;

    public function group(): string;

}