<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\Twig;

use Simp\Pindrop\Modules\structure\src\Entity\NodeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Plugin\PluginManager;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    protected array $fieldTypes = [];
    public function __construct()
    {
        /**@var PluginManager $pluginManager **/
        $pluginManager = \getAppContainer()->get('plugin.manager');
        $fieldsTypes = $pluginManager->getPluginsYamlContent('fields.types');
        foreach ($fieldsTypes as $fieldType) {
            foreach ($fieldType as $name=>$type) {
                if (!empty($type['status']) && !empty($type['class'])) {
                    $this->fieldTypes[$name] = \getAppContainer()->get($type['class']);
                }
            }
        }
    }

    public function getFilters(): array {
        return [
            new TwigFilter('format_phone', [$this, 'formatPhone']),
        ];
    }

    public function getFunctions(): array {
        return [
            new TwigFunction('node_field_value_render', [$this, 'nodeFieldValue']),
        ];
    }

    public function nodeFieldValue(array $settings, NodeInterface $node): Markup
    {
        $fieldType = $this->fieldTypes[$settings['struct_type']] ?? null;
        if ($fieldType instanceof FieldTypeInterface) {
            $values = $node->get($settings['name'])['default'] ?? [];
           $renderableValues = [];
           if (isset($values['values'])) {
               $renderableValues = $values['values'];
           }
           elseif (isset($values['value'])) {
               $renderableValues = [$values['value']];
           }

           return $fieldType->valueRenderResolve()->render($settings, $renderableValues);
        }
        return new Markup('', 'utf-8');
    }

    public function formatPhone(string $phone): string {
        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
        try {
            $numberProto = $phoneUtil->parse($phone);
            return $phoneUtil->format($numberProto, \libphonenumber\PhoneNumberFormat::INTERNATIONAL);
        } catch (\libphonenumber\NumberParseException $e) {
            return $phone;
        }
    }
}