<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Search;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class SearchType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "search";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-search"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Search";
    }

    public function getDescription(): string
    {
        return "A search field with built-in search functionality and styling.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new SearchTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new SearchTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
