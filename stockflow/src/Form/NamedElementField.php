<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Form;

use Simp\Multiform\lib\field\ElementField;

final class NamedElementField extends ElementField
{
    public function __construct(string $element, private readonly string $fieldName)
    {
        parent::__construct($element);
        $this->setHtmlId($fieldName);
    }

    public function getName(): string
    {
        return $this->fieldName;
    }
}
