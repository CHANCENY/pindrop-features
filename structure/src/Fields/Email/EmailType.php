<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Email;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class EmailType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "email";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-envelope"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Email";
    }

    public function getDescription(): string
    {
        return "An email field with built-in validation for email addresses.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new EmailTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }
}
