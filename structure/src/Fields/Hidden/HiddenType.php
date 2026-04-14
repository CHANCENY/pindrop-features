<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Hidden;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class HiddenType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "hidden";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-eye-slash"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Hidden";
    }

    public function getDescription(): string
    {
        return "A hidden field for storing data that is not visible to users.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new HiddenTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new HiddenTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
