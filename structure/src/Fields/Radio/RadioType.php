<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Radio;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class RadioType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "radio";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-dot-circle"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Radio Buttons";
    }

    public function getDescription(): string
    {
        return "Radio buttons for selecting a single option from a predefined list.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new RadioTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }
}
