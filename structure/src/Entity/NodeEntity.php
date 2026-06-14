<?php

namespace Simp\Pindrop\Modules\structure\src\Entity;

use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use PDO;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Modules\structure\src\Plugin\Session\SessionStorage;
use Simp\Pindrop\Routing\Url;
use Throwable;

class NodeEntity implements NodeInterface
{

    protected int $nid = 0;
    protected string $title = '';
    protected string $slug  = '';
    protected \DateTime $createdAt;
    protected \DateTime $updatedAt;
    protected User $author;
    protected string $type = '';
    protected string $uuid = '';
    protected bool $deleted = false;
    protected bool $status  = false;
    protected array $values = [];
    protected array $entityDefinition = [];
    /**
     * @var array|mixed|string|string[]
     */
    public string $preview  = '';
    /**
     * @var true
     */
    public bool $optionalPreview = false;

    /**
     * @throws DependencyException
     * @throws NotFoundException|ContainerExceptionInterface
     */
    public function __construct(protected ContainerInterface $container)
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->author = $this->container->get('current_user')->getUser();
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
       return $this->title;
    }

    /**
     * @inheritDoc
     */
    public function getAuthor(): User
    {
        return $this->author;
    }

    /**
     * @inheritDoc
     */
    public function getAlias(): string
    {
        return $this->slug;
    }

    /**
     * @inheritDoc
     */
    public function bundle(): string
    {
        return $this->type;
    }

    /**
     * @inheritDoc
     */
    public function getAuthorId(): int
    {
        return $this->author->getId();
    }

    /**
     * @inheritDoc
     */
    public function getStatus(): bool
    {
        return $this->status;
    }

    /**
     * @inheritDoc
     */
    public function getAuthorAt(): \DateTime
    {
        return $this->createdAt;
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @inheritDoc
     */
    public function getChangedAt(): \DateTime
    {
       return $this->updatedAt;
    }

    /**
     * @inheritDoc
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * @inheritDoc
     */
    public function isPublished(): bool
    {
        return $this->status;
    }

    /**
     * @inheritDoc
     */
    public function id(): int
    {
       return $this->nid;
    }

    /**
     * @inheritDoc
     */
    public function entityDefinition(): array
    {
        return $this->entityDefinition;
    }

    /**
     * @inheritDoc
     */
    public function get(string $name)
    {
        return $this->values[$name] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function getValues(): array
    {
       return $this->values;
    }

    /**
     * @inheritDoc
     */
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setAuthor(int $uid): static
    {
        $this->author = User::loadById($uid);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setAlias(string $alias): static
    {
        $this->slug = $alias;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setChangedAt(\DateTime $date): static
    {
       $this->updatedAt = $date;
       return $this;
    }

    /**
     * @inheritDoc
     */
    public function setStatus(int $status): static
    {
       $this->status = $status;
       return $this;
    }

    /**
     * @inheritDoc
     */
    public function setAuthorAt(\DateTime $date): static
    {
        $this->createdAt = $date;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setAuthorId(int $uid): static
    {
        $this->author = User::loadById($uid);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function set(string $name, $value): static
    {
        $this->values[$name] = $value;
        return $this;
    }

    /**
     * @inheritDoc
     * @throws DatabaseException
     * @throws Exception
     */
    public function save(): static|bool
    {
        $this->storage()->entityFieldStoragePersist();
        $nid = $this->storage()->entityNodePersist($this);
        if (!empty($nid)) {
            $this->nid = $nid;
            return $this;
        }
        return false;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function delete(): static
    {
        if ($this->storage()->deleteNodeEntity($this)) {
            $this->nid = 0;
            $this->status = false;
            $this->deleted = true;
        }
        return $this;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function find(int $nid): ?static
    {
        try{

            $stmt = \getAppContainer()->get('database')->queryRaw("SELECT * FROM `content_data` WHERE id = ?", $nid);
            $result = $stmt instanceof \PDOStatement ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
            if ($result) {
                $this->nid = $result['id'];
                $this->title = $result['title'];
                $this->slug = $result['slug'];
                $this->type = $result['bundle'];
                $this->uuid = $result['uuid'];
                $this->status = (bool) $result['status'];
                $this->author = $this->container->get('current_user')->getUser();
                $this->createdAt = new \DateTime("@{$result['created']}");
                $this->updatedAt = new \DateTime("@{$result['changed']}");
                $this->deleted = (bool) $result['deleted'];
                $this->storage()->setEntityId($this->type);
                $this->entityDefinition = $this->storage()->getEntityDefinition();
            }
            else {
                return null;
            }

            return $this->collectFields($this->nid);
        }
        catch (Throwable $exception){

        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function findByAlias(string $alias): ?static
    {
        try{

            $stmt = \getAppContainer()->get('database')->queryRaw("SELECT * FROM `content_data` WHERE slug = ?", $alias);
            $result = $stmt instanceof \PDOStatement ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
            if ($result) {
                $this->nid = $result['id'];
                $this->title = $result['title'];
                $this->slug = $result['slug'];
                $this->type = $result['bundle'];
                $this->uuid = $result['uuid'];
                $this->status = (bool) $result['status'];
                $this->author = $this->container->get('current_user')->getUser();
                $this->createdAt = new \DateTime("@{$result['created']}");
                $this->updatedAt = new \DateTime("@{$result['changed']}");
                $this->deleted = (bool) $result['deleted'];
                $this->storage()->setEntityId($this->type);
                $this->entityDefinition = $this->storage()->getEntityDefinition();
            }
            else {
                return null;
            }

            return $this->collectFields($this->nid);

        }
        catch (Throwable $exception){

        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function findByType(string $type): ?array
    {
        try{

            $stmt = \getAppContainer()->get('database')->queryRaw("SELECT id FROM `content_data` WHERE bundle = ?", $type);
            $result = $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            return  array_column($result, 'id');
        }
        catch (Throwable $exception){

        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function findByAuthorId(int $uid): ?array
    {
        try{

            $stmt = \getAppContainer()->get('database')->queryRaw("SELECT id FROM `content_data` WHERE uid = ?", $uid);
            $result = $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            return  array_column($result, 'id');

        }
        catch (Throwable $exception){

        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function findByStatus(int $status): ?array
    {
        try{

            $stmt = \getAppContainer()->get('database')->queryRaw("SELECT id FROM `content_data` WHERE status = ?", $status);
            $result = $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            return  array_column($result, 'id');
        }
        catch (Throwable $exception){

        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function findByAuthors(array $uids): array
    {
        if (empty($uids)) {
            return [];
        }

        try {
            // Create placeholders (?, ?, ?, ...)
            $placeholders = implode(',', array_fill(0, count($uids), '?'));

            $query = "SELECT id FROM `content_data` WHERE uid IN ($placeholders)";

            $stmt = \getAppContainer()->get('database')->queryRaw($query, ...$uids);
            $result = $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            return array_column($result, 'id');
        }
        catch (\Throwable $exception) {
            // optionally log error
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function findByTypes(array $types): array
    {
        if (empty($types)) {
            return [];
        }

        try {
            // Create placeholders (?, ?, ?, ...)
            $placeholders = implode(',', array_fill(0, count($types), '?'));

            $query = "SELECT id FROM `content_data` WHERE bundle IN ($placeholders)";

            $stmt = \getAppContainer()->get('database')->queryRaw($query, ...$types);
            $result = $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            return array_column($result, 'id');
        }
        catch (\Throwable $exception) {
            // optionally log error
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        try {
            // Create placeholders (?, ?, ?, ...)
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $query = "SELECT id FROM `content_data` WHERE id IN ($placeholders)";

            $stmt = \getAppContainer()->get('database')->queryRaw($query, ...$ids);
            $result = $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            return array_column($result, 'id');
        }
        catch (\Throwable $exception) {
            // optionally log error
            return [];
        }
    }

    /**
     * @inheritDoc
     * @throws ContainerExceptionInterface
     */
    public static function create(array $data): static
    {
        return new static(\getAppContainer())->add($data);
    }

    /**
     * @inheritDoc
     * @throws Exception|ContainerExceptionInterface
     */
    public static function load(int $nid): ?static
    {
       return new static(\getAppContainer())->find($nid);
    }

    /**
     * @inheritDoc
     * @throws Exception|ContainerExceptionInterface
     */
    public static function loadByAlias(string $alias): ?static
    {
        return new static(\getAppContainer())->findByAlias($alias);
    }

    /**
     * @inheritDoc
     * @throws Exception|ContainerExceptionInterface
     */
    public static function loadByType(string $type): ?array
    {
        return new static(\getAppContainer())->findByType($type);
    }

    /**
     * @inheritDoc
     * @throws Exception|ContainerExceptionInterface
     */
    public static function loadByAuthorId(int $uid): ?array
    {
        return new static(\getAppContainer())->findByAuthorId($uid);
    }

    /**
     * @inheritDoc
     * @throws Exception|ContainerExceptionInterface
     */
    public static function loadByStatus(int $status): ?array
    {
        return new static(\getAppContainer())->findByStatus($status);
    }

    /**
     * @inheritDoc
     * @throws Exception|ContainerExceptionInterface
     */
    public static function loadByAuthors(array $uids): array
    {
        return new static(\getAppContainer())->findByAuthors($uids);
    }

    /**
     * @inheritDoc
     * @throws ContainerExceptionInterface
     */
    public static function loadMultiple(array $nids): array
    {
       return array_map(function ($nid) {
           return self::load($nid);
       }, $nids);
    }

    /**
     * @inheritDoc
     * @param int|NodeInterface $node
     * @return NodeEntity
     * @throws DatabaseException
     * @throws DependencyException
     * @throws NotFoundException|ContainerExceptionInterface
     */
    public static function duplicate(int|NodeInterface $node): static
    {
        $node = is_int($node) ? self::load($node) : $node;
        return \getAppContainer()->get(static::class)->regenerateNode($node->toArray());
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function storage()
    {
        if (empty($this->type)) {
            throw new Exception("Entity type id not set");
        }
        return \getAppContainer()->get(StorageEntityManager::class)->setEntityId($this->type);
    }

    /**
     * @inheritDoc
     * @throws Exception|ContainerExceptionInterface
     */
    public function add(array $data): static
    {
        // node level validation
        if (empty($data['title'])) {
            throw new Exception("Title can't be empty");
        }

        $this->title = $data['title'];

        if (empty($data['bundle']) && empty($data['type'])) {
            throw new Exception("Bundle can't be empty");
        }

        $this->type = $data['type'] ?? $data['bundle'];

        if (empty($data['author'])) {
            $this->author = $this->container->get('current_user')->getUser();
        }
        else {
            $uid = substr($data['author'],  strrpos($data['author'], '(') + 1, strlen($data['author']));
            $uid = substr($uid, 0,strrpos($uid, ')'));
            if (is_numeric($uid)) {
                $this->author = User::loadById($uid,\getAppContainer()->get('database')) ?? $this->container->get('current_user')->getUser();
            }
        }

        $this->status = !empty($data['status']);
        $this->deleted = !empty($data['deleted']);

        $entityDefinition = $this->storage()->getEntityDefinition();
        if (empty($entityDefinition)) {
            throw new Exception("Entity definition not found");
        }
        $this->entityDefinition = $entityDefinition;

        $fields = $entityDefinition['fields'];

        // now validate the fields data
        foreach ($fields as $name=>$field) {

            if (!empty($field['settings']['status']) && $field['struct_type'] !== 'fieldset' && $field['struct_type'] !== 'detail') {

                // we can handle the data submitted at this point since field is enabled
                $isRequired = (bool) $field['required'] ?? false;
                $defaultValue = $field['defaultValue'] ?? null;
                $cardinality = (int) $field['cardinality'] ?? 1;

                if ($isRequired && empty($defaultValue) && empty($data[$name])) {
                    throw new Exception("Default field {$name} is required and can't be empty");
                }

                $value = $data[$name] ?? $defaultValue;

                if ($cardinality === 0 || $cardinality === 1) {
                    $value = is_array($value) ? $value[0] : $value;


                    if (!$this->validateValue($field, $value)) {
                        throw new Exception("Value {$value} is not valid check if it meet field validation requirements");
                    }

                }

                else {

                    $value = is_array($value) ? $value : [$value];

                    foreach ($value as $key=>$v) {
                        if (!$this->validateValue($field, $v)) {
                            throw new Exception("Value {$v} is not valid check if it meet field validation requirements");
                        }


                    }

                }

                is_array($value) ?
                $this->values[$name]['default']['values'] = $value
                    : $this->values[$name]['default']['value'] = $value;

            }

        }

        // At this point node is processed validation and more

        // Check if you need preview first
        if (!empty($this->entityDefinition['config']['preview']) && intval($this->entityDefinition['config']['preview']) === 2) {

            $sessionId = time();
            SessionStorage::add(['node', 'preview', $sessionId], $this->toArray());

            $this->preview = Url::routeByName('admin.content.add.node.new.submitted.preview', ['sessionId' => $sessionId]);

        }
        elseif (!empty($this->entityDefinition['config']['preview']) && intval($this->entityDefinition['config']['preview']) === 1) {

            $sessionId = time();
            SessionStorage::add(['node', 'preview', $sessionId], $this->toArray());
            $this->preview = Url::routeByName('admin.content.add.node.new.submitted.preview', ['sessionId' => $sessionId]);
            $this->optionalPreview = true;
        }

        return $this;
    }

    private function validateValue(array $field, $value): bool
    {
        $type = $field['struct_type'] ?? null;

        // normalize to array
        $values = is_array($value) ? $value : [$value];

        // ------------------------
        // NUMBER VALIDATION
        // ------------------------
        if ($type === 'number') {
            $min = isset($field['min']) ? (int) $field['min'] : 0;
            $max = isset($field['max']) ? (int) $field['max'] : PHP_INT_MAX;
            $cardinality = isset($field['cardinality']) ? (int) $field['cardinality'] : 1;

            if ($cardinality <= 1) {
                $v = (float) $values[0];
                return ($v >= $min && $v <= $max);
            }

            foreach ($values as $v) {
                if (!is_numeric($v)) {
                    return false;
                }

                $v = (float) $v;

                if ($v < $min || $v > $max) {
                    return false;
                }
            }

            return true;
        }

        // ------------------------
        // OPTIONS VALIDATION
        // ------------------------
        if (in_array($type, ['checkbox', 'radio', 'select'], true)) {
            $options = [];

            $lines = explode("\n", $field['options'] ?? '');
            foreach (array_filter(array_map('trim', $lines)) as $line) {
                $parts = array_map('trim', explode('|', $line, 2));
                $key = $parts[0] ?? null;

                if ($key !== null && $key !== '') {
                    $options[] = $key;
                }
            }

            foreach ($values as $v) {
                if (!in_array($v, $options, true)) {
                    return false;
                }
            }

            return true;
        }

        if ($type === 'text_formatted') {
            if (!empty($field['settings']['text_processing']) && $field['settings']['text_processing'] === 'plain') {
                // return false if $value contains HTML
                if ($value !== strip_tags($value)) {
                    return false;
                }
            }
            return true;
        }

        // ------------------------
        // STRING LENGTH VALIDATION
        // ------------------------
        $maxLength = isset($field['maxLength']) ? (int) $field['maxLength'] : 255;

        foreach ($values as $v) {
            if (strlen((string) $v) > $maxLength) {
                return false;
            }
        }

        return true;
    }


    public function toArray(): array
    {
       return [
           'type' => $this->type,
           'id'   => $this->nid,
           'uid'   => $this->author->getId(),
           'status' => $this->status,
           'deleted' => $this->deleted,
           'uuid'   => $this->uuid,
           'title'  => $this->title,
           'values' => $this->values,

       ];
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws DatabaseException
     */
    public static function fromArray(array $data): static
    {
        return new static(\getAppContainer())->regenerateNode($data);
    }

    /**
     * @throws DatabaseException
     */
    public function regenerateNode(array $data): static
    {
        $this->nid = $data['id'];
        $this->type = $data['type'];
        $this->status = $data['status'];
        $this->deleted = $data['deleted'];
        $this->uuid = $data['uuid'];
        $this->title = $data['title'];
        $this->values = $data['values'];
        $this->entityDefinition = $this->storage()->getEntityDefinition();
        return $this;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Exception
     */
    private function collectFields(int $nid): static
    {
        $values = $this->collectAllDataGrouped($nid);

        $this->values = [];
        foreach ($values as $key=>$value) {

            $list = explode('__field_', $key);
            $fieldName = end($list);
            $this->values[$fieldName]['default'][ count($value) === 1 ? 'value' : 'values'] = count($value) === 1 ? $value[0] : $value;
        }
        return $this;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws Exception
     */
    private function collectAllDataGrouped(int $nid): array
    {
        $references = $this->storage()->entityReferences();
        $queries = [];
        $params  = [];
        $db      = \getAppContainer()->get('database');

        foreach ($references as $ref) {
            $table       = $ref['TABLE_NAME'];
            $entityColumn = $ref['COLUMN_NAME'];
            $parts       = explode('__field_', $table);
            $fieldName   = end($parts);

            $queries[] = "SELECT cd.id, '{$table}' AS source_table, '{$fieldName}' AS field_name, t.`{$fieldName}` AS value FROM content_data cd LEFT JOIN `{$table}` t ON t.`{$entityColumn}` = cd.id WHERE cd.id = ?";
            $params[]  = $nid;
        }

        if (empty($queries)) return [];
        $sql  = implode(' UNION ALL ', $queries);
        $stmt = $db->queryRaw($sql, ...$params);
        $rows = $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        // Group results
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['source_table']][] = $row['value'];
        }

        return $grouped;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     */
    public static function loadByFields(array $field_names = [], array $others = []): array
    {
        $allowedFields = [
            'id' => '=',
            'bundle' => '=',
            'status' => '=',
            'deleted' => '=',
            'uuid' => '=',
            'title' => 'LIKE',
            'uid'   => '='
        ];

        $conditions = [];
        $params = [];

        // Build WHERE clause
        if (!empty($field_names)) {
            foreach ($field_names as $key => $value) {
                if (!isset($allowedFields[$key])) {
                    continue;
                }

                $operator = $allowedFields[$key];

                if ($operator === 'LIKE') {
                    $conditions[] = "{$key} LIKE :{$key}";
                    $params[$key] = "%{$value}%";
                } else {
                    $conditions[] = "{$key} {$operator} :{$key}";
                    $params[$key] = $value;
                }
            }
        }

        $whereSql = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Pagination defaults
        $limit = isset($others['limit']) ? (int)$others['limit'] : 25;
        $offset = isset($others['offset']) ? (int)$others['offset'] : 0;

        // --- TOTAL COUNT ---
        $db         = \getAppContainer()->get('database');
        $countSql   = "SELECT COUNT(*) as total FROM content_data {$whereSql}";
        $countStmt  = $db->queryRaw($countSql, ...array_values($params));
        $total      = (int)($countStmt instanceof \PDOStatement ? $countStmt->fetchColumn() : 0);

        // --- PAGINATED QUERY ---
        $sql   = "SELECT id FROM content_data {$whereSql} ORDER BY changed DESC LIMIT ? OFFSET ?";
        $bindings = array_values($params);
        $bindings[] = $limit;
        $bindings[] = $offset;
        $stmt  = $db->queryRaw($sql, ...$bindings);
        $rows  = $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        // Load full nodes
        $nodes = array_map(function ($row) {
            return self::load($row['id']);
        }, $rows);

        return [
            'nodes' => $nodes,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total,
            ]
        ];
    }


    public function setDeleted(bool $deleted): static
    {
        $this->deleted = $deleted;
        if ($deleted) {
            $this->setStatus(0);
        }
        return $this;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setId(int $id): static
    {
        $this->nid = $id;
        return $this;
    }
}