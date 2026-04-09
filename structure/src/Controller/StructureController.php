<?php

namespace Simp\Pindrop\Modules\structure\src\Controller;

use DI\DependencyException;
use DI\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\structure\src\Plugin\Fields\FieldTypeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\NodeType\NodeType;
use Simp\Pindrop\Modules\structure\src\Plugin\NodeType\NodeTypeConfiguration;
use Simp\Pindrop\Modules\structure\src\Plugin\Session\SessionStorage;
use Simp\Pindrop\Modules\structure\src\Services\StructureManager;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StructureController extends ControllerBase
{

    public function __construct(protected StructureManager $structureManager,
                                protected NodeTypeConfiguration $nodeTypeConfiguration,
                                protected NodeType $nodeType,
    )
    {
        parent::__construct();
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public static function create(ContainerInterface $container): StructureController
    {
        return new self(
            $container->get('structure.repository'),
            $container->get('structure.content_type'),
            $container->get(NodeType::class),
        );
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function structure(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig("@structure/structure.html.twig", [
            "structures" => $this->structureManager->getStructures(),
        ]);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws DatabaseException
     */
    public function structureContentTypesCreate(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod(Request::METHOD_POST)) {
            $configurations             = $request->request->all();
            $configurations['status']   = !empty($configurations['status']) ? 1 : 0;
            $configurations['revision'] = !empty($configurations['revision']) ? 1 : 0;
            $configurations['promote']  = !empty($configurations['promote']) ? 1 : 0;
            $configurations['submitted']= !empty($configurations['submitted']) ? 1 : 0;
            $configurations['label']    = $configurations['name'];
            $configurations['name']     = $configurations['machine_name'];
            unset($configurations['_csrf_token']);

            $nodeTypeNameComponent = [
                $this->nodeType->getType(),
                $configurations['name'],
            ];

            if ($this->nodeTypeConfiguration->create($this->nodeType->getType(),$nodeTypeNameComponent,$configurations)) {
                Message::info("{$this->nodeType->getName()} {$configurations['label']} created");
                return $this->redirect(Url::routeByName("structure.structures.content.types"));
            }
            else {
                Message::error("{$this->nodeType->getName()} {$configurations['label']} creation failed");
            }
        }
        return $this->renderTwig("@structure/structure_content_type_create.html.twig", []);
    }

    public function structureContentTypes(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig("@structure/structure_content_types.html.twig", [
            'types' => $this->nodeTypeConfiguration->getContentTypes()
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function structureContentTypesEdit(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get("id");
        $component = ["node", $id];
        $config = $this->nodeTypeConfiguration->getConfiguration($component);

        if ($request->isMethod(Request::METHOD_POST)) {
            $configurations             = $request->request->all();
            $configurations['status']   = !empty($configurations['status']) ? 1 : 0;
            $configurations['revision'] = !empty($configurations['revision']) ? 1 : 0;
            $configurations['promote']  = !empty($configurations['promote']) ? 1 : 0;
            $configurations['submitted']= !empty($configurations['submitted']) ? 1 : 0;
            $configurations['label']    = $configurations['name'];
            $configurations['name']     = $config['config']['machine_name'];
            $configurations['machine_name'] = $config['config']['machine_name'];
            unset($configurations['_csrf_token']);

            $nodeTypeNameComponent = [
                $this->nodeType->getType(),
                $configurations['name'],
            ];

            if ($this->nodeTypeConfiguration->create($this->nodeType->getType(),$nodeTypeNameComponent,$configurations)) {
                Message::info("{$this->nodeType->getName()} {$configurations['label']} updated");
                return $this->redirect(Url::routeByName("structure.structures.content.types"));
            }
            else {
                Message::error("{$this->nodeType->getName()} {$configurations['label']} update failed");
            }
        }

        $config = $this->nodeTypeConfiguration->getConfiguration($component);
        return $this->renderTwig("@structure/structure_content_type_edit.html.twig", [
            'type' => $config,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function structureContentTypesDelete(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get("id");
        $component = ["node", $id];

        $action = $request->query->get("action");

        if ($action == "delete") {
            if ($this->nodeTypeConfiguration->delete($this->nodeType->getType(), $component)){
                Message::info("Deletion completed successfully");
                return $this->redirect(Url::routeByName("structure.structures.content.types"));
            }

            Message::error("deletion failed");
            return $this->redirect(Url::routeByName("structure.structures.content.types"));
        }

        $options['title'] = "Deletion confirmation";
        $options['question'] = "Are you sure you want to do this?";
        return $this->canDelete($request, $route_name, $options);


    }

    public function canDelete(Request $request, string $route_name, array $options): Response
    {
        $action = $request->query->get("action");
        $currentUri = $request->getRequestUri();

        $options['links'] = [
            'confirm' => $currentUri . "?action=delete",
            'cancel' => $currentUri . "?action=cancel",
        ];

        return $this->renderTwig("@structure/structure_can_delete_confirmation.html.twig", $options);
    }

    /**
     * @throws DatabaseException
     */
    public function structureContentTypesFields(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get("id");
        $component = ["node", $id];
        $config = $this->nodeTypeConfiguration->getConfiguration($component);
        $fields = $this->nodeTypeConfiguration->getFieldsByConfigType($component);
        return $this->renderTwig("@structure/structure_content_types_fields.html.twig", [
            'type' => $config,
            'fields' => $fields,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function structureContentTypesFieldsAdd(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get("id");
        $component = ["node", $id];
        $config = $this->nodeTypeConfiguration->getConfiguration($component);

        $fieldTypes = $this->structureManager->getFieldTypes();

        $grouped = [
            'general' => [],
            'reference' => [],
            'media'     => []
        ];

        foreach ($fieldTypes as $fieldType) {
            if ($fieldType instanceof FieldTypeInterface) {
                $grouped[$fieldType->group()][] = $fieldType;
            }
        }

        return $this->renderTwig("@structure/structure_content_types_fields_add.html.twig", [
            'type' => $config,
            'fieldGroups' => $grouped,
        ]);
    }

    public function structureContentTypesFieldsAddType(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get("id");
        $component = ["node", $id];
        $config = $this->nodeTypeConfiguration->getConfiguration($component);

        $type = $request->query->get("type");
        $fieldType = $this->structureManager->getFieldTypesByType($type);

        if ($request->isMethod(Request::METHOD_POST)) {
            $configurations             = $request->request->all();

            unset($configurations['_csrf_token']);
            SessionStorage::add([
                'fields',
                $config['config']['struct_type'],
                'field',
                $configurations['field_machine_name']
            ], $configurations);

            return $this->redirect(Url::routeByName('structure.structures.content.types.manage.fields.add.field.setting',[
                'id' => $config['config']['machine_name'],
                'type' => $fieldType->getType(),
                'name' => $configurations['field_machine_name'],
            ]));
        }

        return $this->renderTwig("@structure/structure_content_types_fields_add_type.html.twig", [
            'type' => $config,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function structureContentTypesFieldsAddTypeSetting(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get("id");
        $component = ["node", $id];
        $config = $this->nodeTypeConfiguration->getConfiguration($component);
        $type = $request->query->get("type");
        $fieldType = $this->structureManager->getFieldTypesByType($type);

        $options['fieldType'] = $fieldType;
        $options['type'] = $config;

        if ($request->isMethod(Request::METHOD_POST)) {
            $configurations             = $request->request->all();
            $configurations['required'] = !empty($configurations['required']);
            unset($configurations['_csrf_token']);

            $fieldBasicSettings = SessionStorage::get([
                'fields',
                $config['config']['struct_type'],
                'field',
                $request->query->get("name"),
            ]);

            if (is_array($fieldBasicSettings)) {
                $configurations = [...$configurations, ...$fieldBasicSettings];

                $validatedSettings = $fieldType->getWidget()->validateFieldSettings($configurations);

                $fieldNameComponent = [
                    'fields',
                    $config['config']['struct_type'],
                    'field',
                    $request->query->get("name"),
                ];

                $validatedSettings['entity_type'] = $config['name'];
                $validatedSettings['settings']['status'] = true;

                if ($this->nodeTypeConfiguration->createField($fieldType->getType(), $fieldNameComponent,$validatedSettings)) {
                    SessionStorage::remove([
                        'fields',
                        $config['config']['struct_type'],
                        'field',
                        $request->query->get("name"),
                    ]);
                    Message::info("Field {$validatedSettings['label']} created successfully");
                    return $this->redirect(Url::routeByName('structure.structures.content.types.manage.fields',[
                        'id' => $config['config']['machine_name'],
                    ]));
                }
            }
            else {
                Message::error("Field creation failed");
                return $this->redirect(Url::routeByName('structure.structures.content.types.manage.fields',[
                    'id' => $config['config']['machine_name'],
                ]));
            }

        }

        return $this->renderTwig("@structure/structure_content_types_fields_add_type_setting.html.twig", [
            'html' => $fieldType->getWidget()->getSettingForm($options)
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function structureContentTypesFieldsAddTypeSettingEdit(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get("id");
        $component = ["node", $id];
        $config = $this->nodeTypeConfiguration->getConfiguration($component);
        $type = $request->query->get("type");
        $fieldType = $this->structureManager->getFieldTypesByType($type);

        $fieldNameComponent = [
            'fields',
            $config['config']['struct_type'],
            'field',
            $request->query->get("name"),
        ];
        $field = $this->nodeTypeConfiguration->getConfiguration($fieldNameComponent);

        $options['fieldType'] = $fieldType;
        $options['type'] = $config;
        $options['field']  = $field;

        if ($request->isMethod(Request::METHOD_POST)) {
            $configurations             = $request->request->all();
            $configurations['required'] = !empty($configurations['required']);
            $configurations['settings']['status'] = !empty($configurations['settings']['status']);

            $validatedSettings = $fieldType->getWidget()->validateFieldSettings($configurations);
            $validatedSettings['settings'] = $configurations['settings'];
            $validatedSettings['entity_type'] = $config['name'];

            $fieldNameComponent = [
                'fields',
                $config['config']['struct_type'],
                'field',
                $request->query->get("name"),
            ];

            if ($this->nodeTypeConfiguration->createField($fieldType->getType(), $fieldNameComponent,$validatedSettings)) {

                Message::info("Field {$validatedSettings['label']} updated successfully");
                return $this->redirect(Url::routeByName('structure.structures.content.types.manage.fields',[
                    'id' => $config['config']['machine_name'],
                ]));
            }
        }

        return $this->renderTwig("@structure/structure_content_types_fields_add_type_setting_edit.html.twig", [
            'html' => $fieldType->getWidget()->getSettingForm($options),
            'form_settings' => $fieldType->getWidget()->getFormDisplaySettings($options),
            ...$options,
        ]);
    }

    public function structureContentTypesFieldsAddTypeSettingDelete(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get("id");
        $component = ["node", $id];
        $config = $this->nodeTypeConfiguration->getConfiguration($component);
        $type = $request->query->get("type");
        $fieldType = $this->structureManager->getFieldTypesByType($type);

        $fieldNameComponent = [
            'fields',
            $config['config']['struct_type'],
            'field',
            $request->query->get("name"),
        ];

        $action = $request->query->get("action");

        if ($action == "delete") {
            if ($this->nodeTypeConfiguration->deleteField($fieldType->getType(), $fieldNameComponent)){
                Message::info("Deletion completed successfully");
                return $this->redirect(Url::routeByName("structure.structures.content.types.manage.fields",[
                    'id' => $id
                ]));
            }

            Message::error("deletion failed");
            return $this->redirect(Url::routeByName("structure.structures.content.types.manage.fields",[
                'id' => $id
            ]));
        }

        $options['title'] = "Deletion confirmation";
        $options['question'] = "Are you sure you want to do this?";
        return $this->canDelete($request, $route_name, $options);
    }

    /**
     * @throws DatabaseException
     */
    public function structureContentTypesFormDisplay(Request $request, string $route_name, array $options): Response
    {
        $id = $request->query->get("id");
        $component = ["node", $id];
        $formDisplayComponent = [
            'node',
            'form',
            'display',
            $id,
            'settings',
        ];
        $config = $this->nodeTypeConfiguration->getConfiguration($component);
        $fields = $this->nodeTypeConfiguration->getFieldsByConfigType($component);

        if ($request->isMethod(Request::METHOD_POST)) {
            $configurations = $request->request->all();
            unset($configurations['_csrf_token']);
            $configurations['entity_type'] = $id;
            if ($this->nodeTypeConfiguration->createFormDisplay('form.display.'.$this->nodeType->getType(), $formDisplayComponent, $configurations)) {
                Message::info("Form display settings successfully saved");
                return $this->redirect(Url::routeByName("structure.structures.content.types.manage.form.display",[
                    'id' => $id
                ]));
            }
        }

        $formDisplaySettings = $this->nodeTypeConfiguration->getConfiguration($formDisplayComponent);

        return $this->renderTwig("@structure/structure_content_types_form_display.html.twig", [
            'type' => $config,
            'fields' => $fields,
            'form_display' => $formDisplaySettings,
        ]);
    }

}