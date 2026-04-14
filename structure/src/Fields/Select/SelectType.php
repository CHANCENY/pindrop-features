<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Select;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class SelectType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "select";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-chevron-down"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Select List";
    }

    public function getDescription(): string
    {
        return "A dropdown menu that allows users to select from a predefined list of options.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new SelectTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new SelectTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
