<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\URL;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class URLType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "url";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-link"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "URL";
    }

    public function getDescription(): string
    {
        return "A URL field for entering web addresses with built-in validation.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new URLTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new URLTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
