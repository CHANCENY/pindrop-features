<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Form;

use InvalidArgumentException;
use Simp\Multiform\lib\Helper;
use Simp\Multiform\lib\form\Form;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\Request;

final class FormRenderer
{
    public function render(
        Request $request,
        string $action,
        array $definitions,
        string $method = 'post',
        string $submitLabel = 'Save changes'
    ): string {
        $form = new Form([
            'method' => $method,
            'action' => $action,
            'class' => 'stockflow-form',
        ]);

        foreach ($definitions as $definition) {
            $type = $definition['type'] ?? 'text';
            $name = $definition['name'] ?? '';
            if ($name === '') {
                throw new InvalidArgumentException('Stockflow form fields require a name.');
            }

            if (in_array($type, ['select', 'textarea'], true)) {
                $field = new NamedElementField($type, $name);
            } else {
                $field = new NamedInputField($type, $name);
            }

            $field->setLabel($definition['label'] ?? '');
            $field->setRequired($definition['required'] ?? false);
            $field->setValue($definition['value'] ?? null);
            if (!empty($definition['multiple'])) {
                $field->setMultiple(true);
            }
            $field->setPlaceholder($definition['placeholder'] ?? '');
            if (isset($definition['options'])) {
                $field->setList($definition['options']);
            }
            foreach ($definition['attributes'] ?? [] as $attribute => $value) {
                $field->setAttribute((string) $attribute, $value);
            }
            if (!empty($definition['class'])) {
                $field->setHtmlClass((array) $definition['class']);
            }

            $form->addField($field, $name);
        }

        if ($method === 'post' && !empty($_ENV['CSRF_TOKEN_SECRET'])) {
            $csrf = new NamedInputField('hidden', '_csrf_token');
            $csrf->setValue(Url::generateToken($request, $_ENV['CSRF_TOKEN_SECRET']));
            $form->addField($csrf, '_csrf_token');
        }

        $submit = new NamedInputField('submit', 'submit');
        $submit->setValue($submitLabel);
        $submit->setHtmlClass(['button', 'primary']);
        $form->addField($submit, 'submit');

        $submitted = $method === 'get' ? $request->query->all() : $request->request->all();
        $wrappers = [];
        $headings = [];
        foreach ($definitions as $definition) {
            $wrappers[$definition['name']] = $definition['wrapper_class'] ?? '';
            $headings[$definition['name']] = $definition['group_title'] ?? '';
        }
        return $form->create($submitted, static function ($field) use ($submitted, $wrappers, $headings): string {
            $markup = Helper::renderField($field, $submitted);
            if ($field->getType() === 'hidden') {
                return $markup;
            }
            $wrapperClass = trim('form-field ' . ($wrappers[$field->getName()] ?? ''));
            $heading = $headings[$field->getName()] ?? '';
            $headingMarkup = $heading === ''
                ? ''
                : '<h3 class="order-form-section">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h3>';
            return $headingMarkup . '<div class="' . htmlspecialchars($wrapperClass, ENT_QUOTES, 'UTF-8') . '">' . $markup . '</div>';
        });
    }
}
