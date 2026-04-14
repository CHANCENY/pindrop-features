<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Submit;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class SubmitType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "submit";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-paper-plane"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Submit Button";
    }

    public function getDescription(): string
    {
        return "A submit button for form submission.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new SubmitTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new SubmitTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
