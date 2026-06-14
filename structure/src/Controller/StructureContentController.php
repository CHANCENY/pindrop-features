<?php

namespace Simp\Pindrop\Modules\structure\src\Controller;

use DI\DependencyException;
use DI\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\structure\src\Entity\Node;
use Simp\Pindrop\Modules\structure\src\Entity\NodeInterface;
use Simp\Pindrop\Modules\structure\src\Plugin\Events\Events;
use Simp\Pindrop\Modules\structure\src\Plugin\NodeType\NodeType;
use Simp\Pindrop\Modules\structure\src\Plugin\NodeType\NodeTypeConfiguration;
use Simp\Pindrop\Modules\structure\src\Plugin\Session\SessionStorage;
use Simp\Pindrop\Modules\structure\src\Services\StructureManager;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Markup;

class StructureContentController extends ControllerBase
{
    public function __construct(protected StructureManager $structureManager,
                                protected NodeTypeConfiguration $nodeTypeConfiguration,
                                protected NodeType $nodeType,
    )
    {
        parent::__construct();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function content(Request $request, string $route_name, array $options): Response
    {
        // Get pagination parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = (int) $request->query->get('limit', 25);
        $offset = ($page - 1) * $limit;

        // Build filter conditions
        $filters = [];
        
        // Title filter
        if ($title = $request->query->get('title')) {
            $filters['title'] = $title;
        }
        
        // Content type filter
        if ($type = $request->query->get('type')) {
           if ($type !== 'all') {
               $filters['bundle'] = $type;
           }
        }
        
        // Status filter
        $status = $request->query->get('status');
        if ($status === 'published') {
            $filters['status'] = 1;
        }
        elseif ($status === 'unpublished') {
            $filters['status'] = 0;
        }
        elseif ($status === 'deleted') {
            $filters['deleted'] = 1;
        }

        // Order by
        $orderBy = $request->query->get('order_by', 'updated_at');
        $orderDirection = $request->query->get('order_direction', 'DESC');

        // Get nodes with filters and pagination
        $result = Node::loadByFields($filters, [
            'limit' => $limit,
            'offset' => $offset,
            'order_by' => $orderBy,
            'order_direction' => $orderDirection
        ]);
        
        $nodes = $result['nodes'] ?? [];
        $paginationData = $result['pagination'] ?? [];
        $total = $paginationData['total'] ?? 0;
        
        // Build pagination data based on actual structure
        $current_page = $page;
        $total_pages = ceil($total / $limit);
        
        $pagination = [
            'current_page' => $current_page,
            'total_pages' => $total_pages,
            'total_items' => $total,
            'items_per_page' => $limit,
            'has_previous' => $current_page > 1,
            'has_next' => $current_page < $total_pages,
            'previous_page' => $current_page > 1 ? $current_page - 1 : null,
            'next_page' => $current_page < $total_pages ? $current_page + 1 : null,
            // Include the original data structure
            'total' => $total,
            'limit' => $paginationData['limit'] ?? $limit,
            'offset' => $paginationData['offset'] ?? $offset,
            'has_more' => $paginationData['has_more'] ?? false,
        ];
        
        // Get available content types for filter dropdown
        $contentTypes = $this->nodeTypeConfiguration->getContentTypes();
        $availableTypes = [];
        foreach ($contentTypes as $contentType) {
            $availableTypes[] = $contentType['config']['machine_name'];
        }
        
        return $this->renderTwig("@structure/content/content.html.twig", [
            'nodes' => $nodes,
            'pagination' => $pagination,
            'filters' => [
                'title' => $request->query->get('title', ''),
                'type' => $request->query->get('type', 'all'),
                'status' => $request->query->get('status', 'all'),
                'lang' => $request->query->get('lang', 'all'),
            ],
            'available_types' => $availableTypes,
            'order_by' => $orderBy,
            'order_direction' => $orderDirection,
        ]);
    }

    public function addContent(Request $request, string $route_name, array $options): Response
    {
        $types = $this->nodeTypeConfiguration->getContentTypes();
        return $this->renderTwig("@structure/content/add.html.twig", [
            'types' => $types,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function addNewContent(Request $request, string $route_name, array $options): Response
    {
        $type = $request->query->get("type");
        $component = ['node', $type];
        $config = $this->nodeTypeConfiguration->getContentType($component);

        \appEvents()->invokeEvents(Events::ST_ENTITY_FORM_PRE_FORM_BUILD,['form'=> &$config]);

        // Group the fields per wrapper if exists
        $wrappers = $config['formDisplay']['config']['parent'] ?? [];
        foreach ($wrappers as $key=>$wrapper) {
            if (!empty($wrapper)) {
                $config['fields'][$wrapper]['fields'][$key] = $config['fields'][$key];
                unset($config['fields'][$key]);
            }
        }

        foreach ($config['fields'] ?? [] as $key=>$field) {

            if (!empty($field['settings']['status'])) {
                $field = $this->getField($field, $request, $config, $key);
                $config['fields'][$key] = $field;
            }
            else {
                unset($config['fields'][$key]);
            }

        }

        \appEvents()->invokeEvents(Events::ST_ENTITY_FORM_POST_BUILD,['form'=> &$config]);

        $action = $request->query->get("action");

        if ($action === 'preview') {
            return $this->redirect(SessionStorage::get(['sessionId','preview', 'link']));
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $failedata = $request->request->all();
            unset($failedata['_csrf_token']);
            SessionStorage::add(['data','failed', $type], $failedata);

            $failedata['type'] = $type;
            try{
                $newNode = Node::create($failedata);
                if ($newNode->optionalPreview === false && !empty($newNode->preview)) {
                    return $this->redirect($newNode->preview);
                }
                elseif ($newNode->optionalPreview === true) {
                    $options['title'] = "Preview confirmation";
                    $options['question'] = "Do you want to preview?";
                    SessionStorage::add(['sessionId','preview', 'link'], $newNode->preview);
                    return $this->canPreview($request,$route_name, $options);
                }
                \appEvents()->invokeEvents(Events::ST_ENTITY_PRE_SAVE, ['node' => &$newNode]);
                $result = $this->saveNode($newNode);
                if ($result) {
                    return $result;
                }
            }catch (Throwable $exception){
                Message::error($exception->getMessage());
            }

        }

        return $this->renderTwig("@structure/content/add_node.html.twig", $config);
    }

    /**
     * @throws DatabaseException
     * @throws ContainerExceptionInterface
     */
    public function addEditContent(Request $request, string $route_name, array $options): Response
    {
        $type = $request->query->get("type");
        $nid = $request->query->get("nid");
        if (empty($nid)) {
            return $this->redirect(Url::routeByName('admin.content'));
        }
        $node = Node::load($nid);
        $component = ['node', $type];
        $config = $this->nodeTypeConfiguration->getContentType($component);
        $config['node'] = $node;

        \appEvents()->invokeEvents(Events::ST_ENTITY_FORM_PRE_FORM_BUILD,['form'=> &$config]);

        // Group the fields per wrapper if exists
        $wrappers = $config['formDisplay']['config']['parent'] ?? [];
        foreach ($wrappers as $key=>$wrapper) {
            if (!empty($wrapper)) {
                $config['fields'][$wrapper]['fields'][$key] = $config['fields'][$key];
                unset($config['fields'][$key]);
            }
        }

        foreach ($config['fields'] ?? [] as $key=>$field) {

            if (!empty($field['settings']['status'])) {
                $field = $this->getField($field, $request, $config, $key);
                $config['fields'][$key] = $field;
            }
            else {
                unset($config['fields'][$key]);
            }

        }

        \appEvents()->invokeEvents(Events::ST_ENTITY_FORM_POST_BUILD,['form'=> &$config]);

        $action = $request->query->get("action");

        if ($action === 'preview') {
            return $this->redirect(SessionStorage::get(['sessionId','preview', 'link']));
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $failedata = $request->request->all();
            unset($failedata['_csrf_token']);
            SessionStorage::add(['data','failed', $type], $failedata);
            $failedata['type'] = $type;

            try{
                $newNode = Node::create($failedata);
                $newNode->setId($node->id());
                if ($newNode->optionalPreview === false && !empty($newNode->preview)) {
                    return $this->redirect($newNode->preview);
                }
                elseif ($newNode->optionalPreview === true) {
                    $options['title'] = "Preview confirmation";
                    $options['question'] = "Do you want to preview?";
                    SessionStorage::add(['sessionId','preview', 'link'], $newNode->preview);
                    return $this->canPreview($request,$route_name, $options);
                }
                \appEvents()->invokeEvents(Events::ST_ENTITY_PRE_SAVE, ['node' => &$newNode]);
                $result = $this->saveNode($newNode);
                if ($result) {
                    return $result;
                }
            }catch (Throwable $exception){
                Message::error($exception->getMessage());
            }

        }

        return $this->renderTwig("@structure/content/edit.html.twig", $config);
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

    public function canPreview(Request $request, string $route_name, array $options): Response
    {
        $action = $request->query->get("action");
        $currentUri = $request->getRequestUri();

        $options['links'] = [
            'confirm' => $currentUri . "?action=preview",
            'cancel' => $currentUri . "?action=save",
        ];

        return $this->renderTwig("@structure/structure_can_preview_confirmation.html.twig", $options);
    }

    private function buildFormFieldTemplate(array &$fields, Request $request, $config): void
    {
        foreach ($fields as $key=>&$field) {
            $field = $this->getField($field, $request, $config, $key);

        }
    }

    /**
     * @param mixed $field
     * @param Request $request
     * @param mixed $config
     * @param int|string $fieldName
     * @return mixed
     */
    public function getField(mixed $field, Request $request, mixed $config, int|string $fieldName): mixed
    {
        try {
            if ($field['struct_type'] === 'fieldset' || $field['struct_type'] === 'detail') {
                $this->buildFormFieldTemplate($field['fields'], $request, $config);
            }

            if ($field['struct_type'] === 'checkbox' || $field['struct_type'] === 'radio' || $field['struct_type'] === 'select') {
                $options = [];
                foreach (array_filter(array_map('trim', explode("\n", $field['options']))) as $line) {
                    [$key, $value] = array_map('trim', explode('|', $line, 2));
                    $options[] = [
                        'value' => $key,
                        'label' => $value,
                    ];
                }
                $field['options'] = $options;
            }

            $overrideTemplate = $field["override_template"] ?? false;
            if ($overrideTemplate) {
                $field['render'] = new Markup($this->renderTwig($overrideTemplate, [
                    'name' => $fieldName,
                    'field' => $field,
                    'entity' => $config['config'] ?? [],
                    'request' => $request,
                ])->getContent(), 'utf-8');
            }
            else {
                $typeTemplate = "@structure/fields/field/{$field['struct_type']}/field.html.twig";
                $field['render'] = new Markup($this->renderTwig($typeTemplate, [
                    'name' => $fieldName,
                    'field' => $field,
                    'entity' => $config['config'] ?? [],
                    'request' => $request,
                ])->getContent(), 'utf-8');
            }

        } catch (\Exception $exception) {
        }
        return $field;
    }

    public function addNewGetContent(Request $request, string $route_name, array $options): JsonResponse
    {

        $data = SessionStorage::get(['data','failed', $request->query->get('type')]);
        return new JsonResponse(['data' => $data]);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException|DatabaseException
     */
    public function previewNodeSubmitted(Request $request, string $route_name, array $options): Response
    {
        $sessionId = $request->query->get('sessionId');
        $node = SessionStorage::get(['node', 'preview', $sessionId]);
        if (empty($node)) {
            Message::error("No preview session found");
        }
        $node = Node::fromArray($node);
        return $this->renderTwig("@structure/content/preview.html.twig", [
            'node' => $node,
            'sessionId' => $sessionId,
            'type' => $node->getType(),
        ]);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException|DatabaseException
     */
    public function previewNodeSubmittedSave(Request $request, string $route_name, array $options): Response
    {
        $sessionId = $request->query->get('sessionId');
        $node = SessionStorage::get(['node', 'preview', $sessionId]);
        if (empty($node)) {
            Message::error("No preview session found");
        }
        $node = Node::fromArray($node);
       
        \appEvents()->invokeEvents(Events::ST_ENTITY_PRE_SAVE, ['node' => &$node]);
        $result = $this->saveNode($node);
        if ($result) {
            return $result;
        }

        Message::error("Node could not be saved");
        return $this->redirect(Url::routeByName('admin.content.add.node',['type' => $node->getType()]));
    }

    /**
     * @throws ContainerExceptionInterface
     */
    public function nodeContent(Request $request, string $route_name, array $options): Response
    {
        $nid = $request->query->get('nid');
        $alias = $request->query->get('node_alias');

        $node = null;
        if (!empty($nid)) {
            $node = Node::load($nid);
        }
        elseif (!empty($alias)) {
            $node = Node::loadByAlias($alias);
        }
      
        return $this->renderTwig("@structure/content/node.html.twig", [
            'node' => $node,
        ]);
    }

    public function previewNodeSubmittedDelete(Request $request, string $route_name, array $options): Response
    {
        $sessionId = $request->query->get('sessionId');
        SessionStorage::remove(['node', 'preview', $sessionId]);
        $type = $request->query->get('type');

        SessionStorage::remove(['data','failed', $type]);
        Message::info("Preview Node has been deleted");

        return $this->redirect(Url::routeByName('admin.content'));
    }

    private function saveNode(NodeInterface $node): ?Response
    {
        $resultNode = $node->save();
        \appEvents()->invokeEvents(Events::ST_ENTITY_POST_SAVE, ['node' => $resultNode]);
        if ($resultNode) {
            Message::info("Node has been saved");
            \appEvents()->invokeEvents(Events::ST_ENTITY_INSERT, ['node' => $resultNode]);

            $slug = $resultNode->getAlias();
            if (!empty($slug)) {
                return $this->redirect(Url::routeByName('admin.content.node.view.alias', ['node_alias' => $slug]));
            }
            else {
                return $this->redirect(Url::routeByName('admin.content.node.view',['nid' => $resultNode->id()]));
            }

        }
        return null;
    }

    /**
     * @throws ContainerExceptionInterface
     */
    public function handleActions(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            $nodes = $request->request->all('nodes');
            $action = $request->request->get('action');

            $nodeEntities = Node::loadMultiple($nodes);

            foreach ($nodeEntities as $nodeEntity) {
                if ($nodeEntity instanceof NodeInterface) {

                    if ($action === 'publish') {
                        $nodeEntity->setStatus(1);
                        $nodeEntity->save();
                    }
                    elseif ($action === 'unpublish') {
                        $nodeEntity->setStatus(0);
                        $nodeEntity->save();
                    }
                    elseif ($action === 'delete') {
                        $nodeEntity->setDeleted(true);
                        $nodeEntity->save();
                    }
                    elseif ($action === 'permanent_delete') {
                        $nodeEntity->delete();
                    }

                }
            }
        }
        return $this->redirect($request->query->get('redirect'));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws \Exception
     */
    public function deleteContent(Request $request, string $route_name, array $options): Response
    {
        $options['title'] = "Permanent Deletion confirmation";
        $options['question'] = "Are you sure you want to do this?";
        $nid = $request->query->get('nid');
        if (empty($nid)) {
            return $this->redirect(Url::routeByName('admin.content'));
        }
        $node = Node::load($nid);

        $action = $request->query->get("action");

        if ($action == "delete") {
            if ($node->delete()->isDeleted()) {
                Message::info("Node has been deleted");
                return $this->redirect(Url::routeByName('admin.content'));
            }
        }
        return $this->canDelete($request, $route_name, $options);
    }

    /**
     * @throws DatabaseException
     * @throws ContainerExceptionInterface
     */
    public function editContent(Request $request, string $route_name, array $options): Response
    {
        $nid = $request->query->get('nid');
        if (empty($nid)) {
            return $this->redirect(Url::routeByName('admin.content'));
        }
        $node = Node::load($nid);

        $type = $node->getType();
        $component = ['node', $type];

        $config = $this->nodeTypeConfiguration->getContentType($component);
        $config['node'] = $node;

        \appEvents()->invokeEvents(Events::ST_ENTITY_FORM_PRE_FORM_BUILD,['form'=> &$config]);

        // Group the fields per wrapper if exists
        $wrappers = $config['formDisplay']['config']['parent'] ?? [];
        foreach ($wrappers as $key=>$wrapper) {
            if (!empty($wrapper)) {
                $config['fields'][$wrapper]['fields'][$key] = $config['fields'][$key];
                unset($config['fields'][$key]);
            }
        }

        foreach ($config['fields'] ?? [] as $key=>$field) {

            if (!empty($field['settings']['status'])) {
                $field = $this->getField($field, $request, $config, $key);
                $config['fields'][$key] = $field;
            }
            else {
                unset($config['fields'][$key]);
            }

        }

        \appEvents()->invokeEvents(Events::ST_ENTITY_FORM_POST_BUILD,['form'=> &$config]);

        $action = $request->query->get("action");

        if ($action === 'preview') {
            return $this->redirect(SessionStorage::get(['sessionId','preview', 'link']));
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $failedata = $request->request->all();
            unset($failedata['_csrf_token']);
            SessionStorage::add(['data','failed', $type], $failedata);

            $failedata['type'] = $type;
            try{
                $newNode = $node;
                if ($newNode->optionalPreview === false && !empty($newNode->preview)) {
                    return $this->redirect($newNode->preview);
                }
                elseif ($newNode->optionalPreview === true) {
                    $options['title'] = "Preview confirmation";
                    $options['question'] = "Do you want to preview?";
                    SessionStorage::add(['sessionId','preview', 'link'], $newNode->preview);
                    return $this->canPreview($request,$route_name, $options);
                }
                \appEvents()->invokeEvents(Events::ST_ENTITY_PRE_SAVE, ['node' => &$newNode]);
                $result = $this->saveNode($newNode);
                if ($result) {
                    return $result;
                }
            }catch (Throwable $exception){
                Message::error($exception->getMessage());
            }

        }
        else {
            $tempData = $node->toArray();
            unset($tempData['values']);

            $array = $node->toArray()['values'];
            foreach ($array as $key=>$value) {
                $dd = $value['default'];
                if (isset($dd['values'])) {
                    $tempData[$key] = $dd['values'];
                }
                elseif (isset($dd['value'])) {
                    $tempData[$key] = $dd['value'];
                }
            }
            SessionStorage::add(['data','failed', $type], $tempData);
        }

        return $this->renderTwig("@structure/content/edit.html.twig",$config);
    }

}