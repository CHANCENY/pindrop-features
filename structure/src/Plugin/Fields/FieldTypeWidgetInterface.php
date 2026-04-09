<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\Fields;

use Simp\Pindrop\Templating\TwigEngine;
use Twig\Markup;

interface FieldTypeWidgetInterface
{
    public function __construct(TwigEngine $twigEngine);

    public function getSettingForm(array $options): Markup;

    public function getSettingFormOptions(): array;

    public function validateFieldSettings(array $unValidatedSettings): array;

    public function getFormDisplaySettings(array $options): Markup;

    public function getDisplaySettings(array $options): Markup;

}