<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Button;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class ButtonType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "button";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-mouse-pointer"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Button";
    }

    public function getDescription(): string
    {
        return "A clickable button for form actions or custom interactions.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new ButtonTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new ButtonTypeFieldContent(\getAppContainer()->get('twig'));
    }

}
