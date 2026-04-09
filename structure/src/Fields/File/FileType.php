<?php

namespace Simp\Pindrop\Modules\structure\src\Fields\File;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeWidgetInterface;
use Twig\Markup;

class FileType implements FieldTypeInterface
{

    public function getType(): string
    {
        return "file";
    }

    public function svgIcon(): \Twig\Markup
    {
        return new Markup('<i class="fas fa-file"></i>', "utf-8");
    }

    public function getLabel(): string
    {
        return "File Upload";
    }

    public function getDescription(): string
    {
        return "A file upload field for accepting user file submissions.";
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getWidget(): FieldTypeWidgetInterface
    {
        return new FileTypeWidget(\getAppContainer()->get('twig'));
    }

    public function group(): string
    {
        return "media";
    }
}
