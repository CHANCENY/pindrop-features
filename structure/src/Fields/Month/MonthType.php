<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Month;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class MonthType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "month";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-calendar-week"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Month";
    }

    public function getDescription(): string
    {
        return "A month picker field for selecting month and year combinations.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new MonthTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }
}
