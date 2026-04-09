<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Fieldset;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class FieldsetType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "fieldset";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-object-group"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Fieldset";
    }

    public function getDescription(): string
    {
        return "A container field that groups related fields together with an optional legend.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new FieldsetTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "wrapper";
    }
}
