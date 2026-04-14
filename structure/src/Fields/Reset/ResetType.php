<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Reset;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class ResetType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "reset";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-undo"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Reset Button";
    }

    public function getDescription(): string
    {
        return "A reset button to clear all form fields to their default values.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new ResetTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new ResetTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
