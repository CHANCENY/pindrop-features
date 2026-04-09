<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\TextFormatted;

use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Simp\Pindrop\Templating\TwigEngine;
use Twig\Markup;

class TextFormattedWidget implements FieldTypeWidgetInterface
{

    protected TwigEngine $twig;

    public function __construct(TwigEngine $twigEngine)
    {
        $this->twig = $twigEngine;
    }

    public function getSettingForm(array $options): Markup
    {
        return new Markup($this->twig->render("@structure/fields/formatted/text_formatted_widget.html.twig", $options), 'utf-8');
    }

    public function getSettingFormOptions(): array
    {
        return [
            'label'    => 'field_label',
            'required' => 'required',
            'maxLength'  => 'max_length',
            'helpText'  => 'help_text',
            'placeholder' => 'placeholder',
            'name'         =>'field_machine_name',
            'defaultValue' => 'default_value',
            'cardinality'  => 'cardinality',
            'comment'  => 'field_description',
            'rows'     => 'rows',
        ];
    }

    public function validateFieldSettings(array $unValidatedSettings): array
    {
        return array_map(function ($unValidatedKey) use ($unValidatedSettings) {
            return $unValidatedSettings[$unValidatedKey] ?? null;
        }, $this->getSettingFormOptions());
    }

    public function getFormDisplaySettings(array $options): Markup
    {
        return new Markup($this->twig->render("@structure/fields/formatted/text_formatted_widget_form_settings.html.twig", $options), 'utf-8');


    }

    public function getDisplaySettings(array $options): Markup
    {
        return new Markup($this->twig->render("@structure/fields/formatted/text_formatted_widget_display.html.twig", $options), 'utf-8');

    }
}