<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Checkbox;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class CheckboxType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "checkbox";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-check-square"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Checkbox";
    }

    public function getDescription(): string
    {
        return "A single checkbox for boolean/on-off values or multiple checkboxes for selecting multiple options.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new CheckboxTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new CheckboxTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
