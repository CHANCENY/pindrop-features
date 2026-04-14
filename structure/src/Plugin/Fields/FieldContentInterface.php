<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\Fields;

use Simp\Pindrop\Templating\TwigEngine;
use Twig\Markup;

interface FieldContentInterface
{
    public function __construct(TwigEngine $twigEngine);

    public function __toString(): string;

    public function render(array $settings, array $values): Markup;
}