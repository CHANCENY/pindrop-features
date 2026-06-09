<?php

namespace Simp\Pindrop\Modules\chat_window\src\Chat;

use Simp\Pindrop\Database\DatabaseService;

class Agent
{
    public function __construct(protected DatabaseService $databaseService)
    {
    }

    public function addAgent(string $first_name, string $last_name, string $email, int $uid, ?string $phone): false|int
    {
        if ($agent = $this->getAgent($uid)) {
            return $agent['id'];
        }

        return $this->databaseService->table('support_team_member')->insert([
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
            'phone'      => $phone,
            'uid'        => $uid,
            'status'     => 'inactive',
        ]);
    }

    public function getAgent(int $uid): array|false
    {
        $row = $this->databaseService->table('support_team_member')
            ->where('uid', '=', $uid)
            ->first();
        return $row ?: false;
    }

    public function getAgentById(int $id): array|false
    {
        $row = $this->databaseService->table('support_team_member')
            ->where('id', '=', $id)
            ->first();
        return $row ?: false;
    }

    public function updateStatus(int $id, string $status): int
    {
        return $this->databaseService->table('support_team_member')
            ->where('id', '=', $id)
            ->update(['status' => $status]);
    }

    public function getAgentSummary(int $uid): array
    {
        $summary['agent']   = $this->getAgent($uid);
        $chatItem           = new ChatItem($this->databaseService);
        $agent              = $this->getAgent($uid);
        $summary['tickets'] = $agent
            ? $chatItem->getChatItemsByAgent((int)$agent['id'], 3)
            : [];
        return $summary;
    }

    public function getAgents(): array
    {
        return $this->databaseService->table('support_team_member')->get();
    }
}
