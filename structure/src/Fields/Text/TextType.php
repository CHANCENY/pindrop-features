<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Text;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class TextType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "text";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-font"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Text (Plain)";
    }

    public function getDescription(): string
    {
        return "A basic text field for simple data like names, SKUs, or short labels.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new TextTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }
}