<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Tel;

use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Simp\Pindrop\Templating\TwigEngine;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Markup;

class TelTypeWidget implements FieldTypeWidgetInterface
{

    protected TwigEngine $twig;
    public function __construct(TwigEngine $twigEngine)
    {
        $this->twig = $twigEngine;
    }

    /**
     * @param array $options
     * @return \Twig\Markup
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function getSettingForm(array $options): \Twig\Markup
    {
        return new Markup($this->twig->render("@structure/fields/tel/type_widget.html.twig", $options), 'utf-8');
    }

    public function getSettingFormOptions(): array
    {
        return [
            'label'    => 'field_label',
            'required' => 'required',
            'maxLength'  => 'max_length',
            'pattern'  => 'pattern',
            'helpText' => 'help_text',
            'placeholder' => 'placeholder',
            'name'         =>'field_machine_name',
            'defaultValue' => 'default_value',
            'cardinality'  => 'cardinality',
            'comment'  => 'field_description',
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
        return new Markup($this->twig->render("@structure/fields/tel/type_widget_form_settings.html.twig", $options), 'utf-8');
    }

    public function getDisplaySettings(array $options): Markup
    {
        return new Markup($this->twig->render("@structure/fields/tel/type_widget_display.html.twig", $options), 'utf-8');
    }
}
