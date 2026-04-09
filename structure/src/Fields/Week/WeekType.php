<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Week;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class WeekType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "week";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-calendar-day"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Week";
    }

    public function getDescription(): string
    {
        return "A week picker field for selecting week numbers and years.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new WeekTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }
}
