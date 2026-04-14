<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Number;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class NumberType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "number";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-hashtag"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Number";
    }

    public function getDescription(): string
    {
        return "A numeric field for integers, decimals, and floating point numbers.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new NumberTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new NumberTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
