<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Form;

use Simp\Multiform\lib\field\InputField;

final class NamedInputField extends InputField
{
    public function __construct(string $type, private readonly string $fieldName)
    {
        parent::__construct($type);
        $this->setHtmlId($fieldName);
    }

    public function getName(): string
    {
        return $this->fieldName;
    }
}
