<?php

namespace Simp\Pindrop\Modules\structure\src\Entity;

use Exception;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\structure\src\Plugin\NodeType\NodeTypeConfiguration;
use Throwable;

class StorageEntityManager
{
    private string $entityId;

    public function __construct(public DatabaseService $databaseService,
                                protected NodeTypeConfiguration $nodeTypeConfiguration,
    )
    {
        $this->entityId = "";
    }

    public function setEntityId(string $entityId): StorageEntityManager
    {
        $this->entityId = $entityId;
        return $this;
    }

    /**
     * @throws DatabaseException
     */
    public function getEntityDefinition(): array
    {
        return $this->nodeTypeConfiguration->getContentType(['node', $this->entityId]);
    }

    /**
     * @throws DatabaseException
     * @throws Exception
     */
    public function entityFieldStoragePersist(): void
    {
        // Now we have to create tables here
        $entityDefinition = $this->getEntityDefinition();
        if (empty($entityDefinition)) {
            throw new Exception("Entity definition not found in configuration");
        }

        $queries = [];

        foreach ($entityDefinition['fields'] as $name=>$fieldDefinition) {

            if ($fieldDefinition['struct_type'] !== 'fieldset' && $fieldDefinition['struct_type'] !== 'detail') {

                $t = [];
                $comment = $fieldDefinition['comment'] ?? null;
                $defaultValue = $fieldDefinition['defaultValue'] ?? null;
                $isRequired = $fieldDefinition['required'] ?? false;
                $tableName  = "node__field_{$name}";

                if (!$this->databaseService->tableExists($tableName)) {
                    $queryComponent = [
                        "CREATE TABLE IF NOT EXISTS `$tableName` (",
                        "id BIGINT AUTO_INCREMENT PRIMARY KEY,",
                        "entity_id BIGINT UNSIGNED NOT NULL,",
                    ];

                    if ($fieldDefinition['struct_type'] === 'checkbox' || $fieldDefinition['struct_type'] === 'radio') {
                        $t[] = "`{$name}` VARCHAR(255)";
                        if ($isRequired) {
                            $t[] = "NOT NULL";
                        }
                        if (!empty($defaultValue)) {
                            $t[] = "DEFAULT '{$defaultValue}'";
                        }
                        $queryComponent[] = implode(" ", $t);
                    }
                    elseif ($fieldDefinition['struct_type'] === 'text_formatted') {
                        $maxLength = !empty($fieldDefinition['maxLength']) ? (int) $fieldDefinition['maxLength'] : 3000;
                        $t[] = "`{$name}` VARCHAR({$maxLength})";
                        if ($isRequired) {
                            $t[] = "NOT NULL";
                        }
                        if (!empty($defaultValue)) {
                            $t[] = "DEFAULT '{$defaultValue}'";
                        }
                        $queryComponent[] = implode(" ", $t);
                    }
                    elseif ($fieldDefinition['struct_type'] === 'number' || $fieldDefinition['struct_type'] === 'file' || $fieldDefinition['struct_type'] === 'image') {
                        $t[] = "`{$name}` BIGINT UNSIGNED";
                        if ($isRequired) {
                            $t[] = "NOT NULL";
                        }
                        if (!empty($defaultValue)) {
                            $t[] = "DEFAULT '{$defaultValue}'";
                        }
                        $queryComponent[] = implode(" ", $t);
                    }
                    else {
                        $maxLength = !empty($fieldDefinition['maxLength']) ? (int) $fieldDefinition['maxLength'] : 3000;
                        $t[] = "`{$name}` VARCHAR({$maxLength})";
                        if ($isRequired) {
                            $t[] = "NOT NULL";
                        }
                        if (!empty($defaultValue)) {
                            $t[] = "DEFAULT '{$defaultValue}'";
                        }
                        $queryComponent[] = implode(" ", $t);
                    }

                    if ($comment) {
                        $queryComponent[] = "COMMENT '$comment'";
                    }

                    $queryLine = implode(" ", $queryComponent);
                    $queryLine = trim($queryLine, ',');
                    $queryLine .= ", CONSTRAINT fk_{$tableName} FOREIGN KEY (entity_id) REFERENCES content_data(id) ON DELETE CASCADE ON UPDATE CASCADE";
                    $queryLine .= ")";
                    $queries[] = trim($queryLine, ',');
                }

            }
        }
        $queries = implode(";\n", $queries);
        $this->databaseService->getPdo()->exec("SET FOREIGN_KEY_CHECKS = 0;");
        if (!empty($queries)) {
            $results = $this->databaseService->getPdo()->exec($queries);
        }
        $this->databaseService->getPdo()->exec("SET FOREIGN_KEY_CHECKS = 1;");
    }

    public function entityNodePersist(NodeInterface $node): int|bool
    {
        try{

            $topStatement = null;
            $pdoConnection = $this->databaseService->getPdo();
            if (!empty($node->id())) {

                // update the node
              $topLevelQuery = "UPDATE `content_data` SET `title` = :title, `status` = :status, `uid` = :uid, `deleted` = :deleted, `changed` = :changed WHERE `id` = :id";
                $topStatement = $pdoConnection->prepare($topLevelQuery);

                $topStatement->bindValue('title', $node->getTitle());
                $topStatement->bindValue('status', (int) $node->getStatus());
                $topStatement->bindValue('uid', $node->getAuthor()->getId());
                $topStatement->bindValue('deleted', (int) $node->isDeleted());
                $topStatement->bindValue('id', $node->id());
            }
            else {
                $topLevelQuery = "INSERT INTO `content_data` (`title`, `status`, `uid`, `deleted`, `created`, `changed`, `bundle`) VALUES (:title, :status, :uid, :deleted, :created, :changed, :bundle)";
                $topStatement = $pdoConnection->prepare($topLevelQuery);
                $topStatement->bindValue('title', $node->getTitle());
                $topStatement->bindValue('status',(int) $node->getStatus());
                $topStatement->bindValue('uid', $node->getAuthor()->getId());
                $topStatement->bindValue('deleted',(int) $node->isDeleted());
                $topStatement->bindValue('created', $node->getAuthorAt()->getTimestamp());
                $topStatement->bindValue('bundle', $node->getType());
            }
            $topStatement->bindValue('changed', $node->getChangedAt()->getTimestamp());

            if ($topStatement->execute()) {
                $nid = empty($node->id()) ? $this->databaseService->lastInsertId() : $node->id();
                $pdoConnection->beginTransaction();
                foreach ($node->getValues() as $name=>$value) {
                    if ($pdoConnection->prepare("DELETE FROM `node__field_{$name}` WHERE `entity_id` = '{$nid}'")->execute()) {
                        $values = !empty($value['default']['values']) ? $value['default']['values'] : [$value['default']['value']];
                        $placeholders = [];
                        $vPlaceholders = [];
                        foreach ($values as $k=>$vv) {
                            $placeholders[]  = "(:entity{$k}, :v{$k})";
                            $vPlaceholders[':entity'.$k] = $nid;
                            $vPlaceholders[':v'.$k] = $vv;
                        }
                        $query = "INSERT INTO `node__field_{$name}` (`entity_id`, `{$name}`) VALUES ".implode(', ', $placeholders);
                        $statement = $pdoConnection->prepare($query);
                        $statement->execute($vPlaceholders);
                    }

                }
                $pdoConnection->commit();
                return $nid;
            }
        }
        catch (Throwable $exception){

        }
        return false;

    }

    public function entityReferences()
    {
        $query = "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = 'content_data' AND REFERENCED_COLUMN_NAME = 'id' AND COLUMN_NAME = 'entity_id' AND TABLE_SCHEMA = DATABASE();";
        $statement = $this->databaseService->getPdo()->prepare($query);
        $statement->execute();
        $result = $statement->fetchAll();
        return $result;
    }

    public function deleteNodeEntity(NodeInterface $node): bool
    {
        $query = 'DELETE FROM `content_data` WHERE `id` = :id';
        return $this->databaseService->getPdo()->prepare($query)->execute(['id' => $node->id()]);
    }
}