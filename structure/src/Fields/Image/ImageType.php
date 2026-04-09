<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\Image;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class ImageType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "image";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-image"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "Image Upload";
    }

    public function getDescription(): string
    {
        return "An image upload field for accepting image files with preview functionality.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new ImageTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "media";
    }
}
