<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\TextFormatted;

use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class TextFormatted implements FieldTypeInterface
{

    public function getType(): string
    {
        return "text_formatted";
    }

    public function svgIcon(): Markup
    {
        return new Markup('<i class="fas fa-align-left"></i>', 'utf-8');
    }

    public function getLabel(): string
    {
        return "Text (Formatted, Long)";
    }

    public function getDescription(): string
    {
        return "A text area with a rich text editor for longer content like descriptions.";
    }

    public function getWidget(): FieldTypeWidgetInterface
    {
        return new TextFormattedWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }
}