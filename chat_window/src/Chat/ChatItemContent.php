<?php

namespace Simp\Pindrop\Modules\chat_window\src\Chat;

use Simp\Pindrop\Database\DatabaseService;

class ChatItemContent
{
    public function __construct(protected DatabaseService $databaseService)
    {
    }

    public function addContent(int $cid, array $data): bool|int
    {
        return $this->databaseService->table('chat_item_data')->insert([
            'cid'          => $cid,
            'message_type' => $data['message_type'] ?? 'customer',
            'content'      => $data['content'] ?? '',
        ]);
    }

    public function getContents(int $cid): array
    {
        return $this->databaseService->table('chat_item_data')
            ->where('cid', '=', $cid)
            ->oldest('created_at')
            ->get();
    }

    public function getContent(int $id): ?array
    {
        return $this->databaseService->table('chat_item_data')
            ->where('id', '=', $id)
            ->first();
    }

    public function updateContent(int $id, string $content): int
    {
        return $this->databaseService->table('chat_item_data')
            ->where('id', '=', $id)
            ->update(['content' => $content]);
    }

    public function deleteContent(int $id): int
    {
        return $this->databaseService->table('chat_item_data')
            ->where('id', '=', $id)
            ->delete();
    }
}
