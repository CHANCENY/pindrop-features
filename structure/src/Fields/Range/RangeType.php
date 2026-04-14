<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Range;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class RangeType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "range";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-sliders-h"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Range Slider";
    }

    public function getDescription(): string
    {
        return "A range slider for selecting numeric values within a specified range.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new RangeTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new RangeTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
