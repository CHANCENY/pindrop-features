<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Date;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class DateType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "date";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-calendar"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Date";
    }

    public function getDescription(): string
    {
        return "A date picker field for selecting calendar dates.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new DateTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new DateTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
