<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Detail;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class DetailType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "detail";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-info-circle"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Details";
    }

    public function getDescription(): string
    {
        return "A collapsible details/summary element for organizing content with expandable sections.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new DetailTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "wrapper";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new DetailTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
