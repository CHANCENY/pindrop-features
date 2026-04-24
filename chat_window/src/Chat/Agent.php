<?php

namespace Simp\Pindrop\Modules\chat_window\src\Chat;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;

class Agent
{
    public function __construct(protected DatabaseService $databaseService)
    {
    }

    /**
     * @throws DatabaseException
     */
    public function addAgent(string $first_name, string $last_name, string $email, int $uid, ?string $phone): false|int
    {
        $data = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'uid' => $uid,
            'status' => 'inactive'
        ];

        if ($agent = $this->getAgent($uid)) {
            return $agent['id'];
        }

        $query = "INSERT INTO support_team_member (first_name, last_name, email, phone, uid, status) VALUES(:first_name, :last_name, :email, :phone, :uid, :status)";

        $st = $this->databaseService->query($query, ...$data);

        if($st){
            return $this->databaseService->lastInsertId();
        }
        return false;
    }

    /**
     * @throws DatabaseException
     */
    public function getAgent(int $uid)
    {
        $query = "SELECT * FROM support_team_member WHERE uid = :uid";
        $st = $this->databaseService->query($query, $uid);
        if($st){
            return $st->fetch();
        }
        return false;
    }

    /**
     * @throws DatabaseException
     */
    public function updateStatus(int $id, string $status): false|int
    {
        $query = "UPDATE support_team_member SET status = :status WHERE id = :id";
        $data = [
            'status' => $status,
            'id' => $id
        ];
        $st = $this->databaseService->query($query, ...$data);
        if($st){
            return $st->rowCount();
        }
        return false;
    }

    /**
     * @throws DatabaseException
     */
    public function getAgentSummary(int $id): array
    {
        $summary['agent'] = $this->getAgent($id);

        $chatItem = new ChatItem($this->databaseService);

        $summary['tickets'] = $chatItem->getChatItemsByAgent($id, 3);

        return $summary;
    }

    /**
     * @throws DatabaseException
     */
    public function getAgents(): array
    {
        $query = "SELECT * FROM support_team_member";
        $st = $this->databaseService->query($query);
        return $st->fetchAll();
    }
}