<?php

namespace Simp\Pindrop\Modules\structure\src\Entity;

use Exception;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabasePermissionGuard;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\structure\src\Plugin\NodeType\NodeTypeConfiguration;
use Throwable;

class StorageEntityManager
{
    private string $entityId;

    public function __construct(
        public DatabaseService $databaseService,
        protected NodeTypeConfiguration $nodeTypeConfiguration,
    ) {
        $this->entityId = '';
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
     * Create dynamic field storage tables for a content type.
     * Uses execRaw() because DDL (CREATE TABLE) is SchemaHandler-level work —
     * it cannot go through QueryBuilder.
     * DatabasePermissionGuard::bypass() wraps both the DDL and the FK check
     * so the permission guard doesn't interfere with schema operations.
     *
     * @throws DatabaseException
     * @throws Exception
     */
    public function entityFieldStoragePersist(): void
    {
        $entityDefinition = $this->getEntityDefinition();
        if (empty($entityDefinition)) {
            throw new Exception('Entity definition not found in configuration');
        }

        $queries = [];

        foreach ($entityDefinition['fields'] as $name => $fieldDefinition) {
            if (in_array($fieldDefinition['struct_type'], ['fieldset', 'detail'], true)) {
                continue;
            }

            $tableName = "node__field_{$name}";
            if ($this->databaseService->tableExists($tableName)) {
                continue;
            }

            $comment      = $fieldDefinition['comment']      ?? null;
            $defaultValue = $fieldDefinition['defaultValue'] ?? null;
            $isRequired   = $fieldDefinition['required']     ?? false;

            $queryComponent = [
                "CREATE TABLE IF NOT EXISTS `$tableName` (",
                'id BIGINT AUTO_INCREMENT PRIMARY KEY,',
                'entity_id BIGINT UNSIGNED NOT NULL,',
            ];

            $colParts = [];
            $type     = $fieldDefinition['struct_type'];

            if (in_array($type, ['checkbox', 'radio'], true)) {
                $colParts[] = "`{$name}` VARCHAR(255)";
            } elseif ($type === 'text_formatted') {
                $max        = !empty($fieldDefinition['maxLength']) ? (int)$fieldDefinition['maxLength'] : 3000;
                $colParts[] = "`{$name}` LONGTEXT";
            } elseif (in_array($type, ['number', 'file', 'image'], true)) {
                $colParts[] = "`{$name}` BIGINT UNSIGNED";
            } else {
                $max        = !empty($fieldDefinition['maxLength']) ? (int)$fieldDefinition['maxLength'] : 3000;
                $colParts[] = "`{$name}` VARCHAR({$max})";
            }

            if ($isRequired)          $colParts[] = 'NOT NULL';
            if (!empty($defaultValue)) $colParts[] = "DEFAULT '" . addslashes($defaultValue) . "'";
            if ($comment)              $colParts[] = "COMMENT '" . addslashes($comment) . "'";

            $queryComponent[] = implode(' ', $colParts) . ',';
            $queryComponent[] = "CONSTRAINT fk_{$tableName} FOREIGN KEY (entity_id) REFERENCES content_data(id) ON DELETE CASCADE ON UPDATE CASCADE";
            $queryComponent[] = ')';

            $queries[] = implode(' ', $queryComponent);
        }

        if (empty($queries)) {
            return;
        }

        // DDL runs with bypass — the guard has no business blocking CREATE TABLE
        DatabasePermissionGuard::bypass(true);
        try {
            $this->databaseService->execRaw('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($queries as $ddl) {
                $this->databaseService->execRaw($ddl);
            }
            $this->databaseService->execRaw('SET FOREIGN_KEY_CHECKS = 1');
        } finally {
            DatabasePermissionGuard::bypass(false);
        }
    }

    /**
     * Persist a node and its field values.
     * content_data is a core table — we use queryRaw() which internally
     * bypasses the guard (same as SchemaHandler pattern).
     *
     * @throws DatabaseException
     */
    public function entityNodePersist(NodeInterface $node): int|bool
    {
        try {
            // Persist field values inside a transaction
            $this->databaseService->beginTransaction();
            $nid = null;

            if (!empty($node->id())) {
                // Update existing node
                $this->databaseService->queryRaw(
                    'UPDATE `content_data` SET `title` = ?, `status` = ?, `uid` = ?, `deleted` = ?, `changed` = ? WHERE `id` = ?',
                    $node->getTitle(),
                    (int)$node->getStatus(),
                    $node->getAuthor()->getId(),
                    (int)$node->isDeleted(),
                    $node->getChangedAt()->getTimestamp(),
                    $node->id()
                );
                $nid = $node->id();
            } else {
                // Insert new node
                $this->databaseService->queryRaw(
                    'INSERT INTO `content_data` (`title`, `status`, `uid`, `deleted`, `created`, `changed`, `bundle`) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    $node->getTitle(),
                    (int)$node->getStatus(),
                    $node->getAuthor()->getId(),
                    (int)$node->isDeleted(),
                    $node->getAuthorAt()->getTimestamp(),
                    $node->getChangedAt()->getTimestamp(),
                    $node->getType()
                );
                $nid = $this->databaseService->lastInsertId();
            }

            if (!$nid) {
                return false;
            }

            

            foreach ($node->getValues() as $name => $value) {
                // Delete existing field values — dynamic table, bypass guard
                
                $table_name = "node__field_{$name}";
                $this->databaseService->queryRaw(
                    "DELETE FROM `node__field_{$name}` WHERE `entity_id` = ?",
                    $nid
                );
                $this->databaseService->table($table_name)->where('entity_id', '=', $nid)->delete();

                $values       = !empty($value['default']['values'])
                    ? $value['default']['values']
                    : [$value['default']['value']];
                $placeholders = [];
                $bindings     = [];

                foreach ($values as $k => $vv) {
                    
                   $this->databaseService->table($table_name)->insert([
                    'entity_id' => $nid,
                    $name       => $vv,
                   ]);
                }

               
            }

            $this->databaseService->commit();
            return $nid;

        } catch (Throwable $e) {
            $this->databaseService->rollback();
            return false;
        }
    }

    /**
     * Get all tables that reference content_data via entity_id foreign key.
     */
    public function entityReferences(): array
    {
        $stmt = $this->databaseService->queryRaw(
            "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE REFERENCED_TABLE_NAME = 'content_data'
               AND REFERENCED_COLUMN_NAME = 'id'
               AND COLUMN_NAME = 'entity_id'
               AND TABLE_SCHEMA = DATABASE()"
        );
        return $stmt instanceof \PDOStatement ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /**
     * Delete a node from content_data.
     * content_data is a core table — uses queryRaw() to bypass the guard.
     */
    public function deleteNodeEntity(NodeInterface $node): bool
    {
        $stmt = $this->databaseService->queryRaw(
            'DELETE FROM `content_data` WHERE `id` = ?',
            $node->id()
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }
}
