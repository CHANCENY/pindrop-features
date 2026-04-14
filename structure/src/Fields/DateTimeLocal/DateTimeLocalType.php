<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\DateTimeLocal;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class DateTimeLocalType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "datetime-local";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-calendar-alt"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Date & Time (Local)";
    }

    public function getDescription(): string
    {
        return "A date and time picker field for selecting local date and time without timezone.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new DateTimeLocalTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new DateTimeLocalTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
