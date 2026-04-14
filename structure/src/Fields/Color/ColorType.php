<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Color;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class ColorType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "color";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-palette"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Color Picker";
    }

    public function getDescription(): string
    {
        return "A color picker field for selecting hex color values.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new ColorTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new ColorTypeFieldContent(\getAppContainer()->get('twig'));
    }

}
