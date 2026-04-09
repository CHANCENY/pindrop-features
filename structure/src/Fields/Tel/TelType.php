<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Tel;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class TelType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "tel";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-phone"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Telephone";
    }

    public function getDescription(): string
    {
        return "A telephone number field optimized for mobile devices with numeric keypad.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new TelTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }
}
