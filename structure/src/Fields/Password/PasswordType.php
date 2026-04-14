<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Password;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class PasswordType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "password";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-lock"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Password";
    }

    public function getDescription(): string
    {
        return "A password field for sensitive information input with masked characters.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new PasswordTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "general";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new PasswordTypeFieldContent(\getAppContainer()->get('twig'));
    }
}
