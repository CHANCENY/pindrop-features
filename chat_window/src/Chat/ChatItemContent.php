<?php

namespace Simp\Pindrop\Modules\chat_window\src\Chat;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;

class ChatItemContent
{
    public function __construct(protected DatabaseService $databaseService)
    {
    }

    /**
     * @throws DatabaseException
     */
    public function addContent(int $cid, array $data): bool|int
    {
        $data['cid'] = $cid;
        $query = "INSERT INTO chat_item_data (message_type, cid, content) VALUES (:message_type, :cid, :content)";
        $st = $this->databaseService->query($query, ...$data);
        if (!$st) {
            return false;
        }
        return $this->databaseService->lastInsertId();
    }

    /**
     * @throws DatabaseException
     */
    public function getContents(int $cid): array
    {
        $query = "SELECT * FROM chat_item_data WHERE cid = :cid";
        $st = $this->databaseService->query($query, $cid);
        return$st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @throws DatabaseException
     */
    public function deleteContent(int $id): int
    {
        $query = "DELETE FROM chat_item_data WHERE id = :id";
        $st = $this->databaseService->query($query, $id);
        return $st->rowCount();
    }

    /**
     * @throws DatabaseException
     */
    public function getContent(int $id): array {
        $query = "SELECT * FROM chat_item_data WHERE id = :id";
        $st = $this->databaseService->query($query, $id);
        return$st->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @throws DatabaseException
     */
    public function updateContent(int $id, string $content): bool {
        $query = "UPDATE chat_item_data SET content = :content WHERE id = :id";
        $st = $this->databaseService->query($query, $content, $id);
        return $st->rowCount();
    }
}