<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Time;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class TimeType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "time";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-clock"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Time";
    }

    public function getDescription(): string
    {
        return "A time picker field for selecting time values without timezone.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new TimeTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new TimeTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
