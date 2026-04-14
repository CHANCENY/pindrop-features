<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\User;

use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldContentInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class UserType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "user";
    }

    public function svgIcon(): Markup
    {
        return new Markup('<i class="fas fa-user"></i>', 'utf-8');
    }

    public function getLabel(): string
    {
        return 'User Reference';
    }

    public function getDescription(): string
    {
        return  "Links the content to a specific user account on the system.";
    }

    public function getWidget(): FieldTypeWidgetInterface
    {
        return new UserTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "reference";
    }

    public function valueRenderResolve(): FieldContentInterface
    {
        return new UserTypeFieldContent(\getAppContainer()->get('twig'));
    }
}