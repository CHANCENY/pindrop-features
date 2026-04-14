<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Month;

use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Templating\TwigEngine;
use Twig\Markup;

class MonthTypeFieldContent implements FieldContentInterface
{
    protected TwigEngine $twigEngine;
    protected array $settings;
    protected array $values;
    private Markup $content;

    public function __construct(TwigEngine $twigEngine)
    {
        $this->twigEngine = $twigEngine;
    }

    public function __toString(): string
    {
        return $this->content ?? "";
    }

    public function render(array $settings, array $values): Markup
    {
        $this->settings = $settings;
        $this->values = $values;

        $this->content = new Markup($this->twigEngine->render('@structure/fields/field/month/content.html.twig', [
            'settings' => $this->settings,
            'values' => $this->values,
        ]),'utf-8');
        return $this->content;
    }
}
