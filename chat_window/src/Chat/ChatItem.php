<?php

namespace Simp\Pindrop\Modules\chat_window\src\Chat;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTime;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\chat_window\src\Customer\Customer;

class ChatItem
{
    public const array alphabetColors = [
    'A' => 'linear-gradient(135deg, #f59e0b, #ef4444)',
    'B' => 'linear-gradient(135deg, #3b82f6, #06b6d4)',
    'C' => 'linear-gradient(135deg, #10b981, #22c55e)',
    'D' => 'linear-gradient(135deg, #8b5cf6, #ec4899)',
    'E' => 'linear-gradient(135deg, #f97316, #eab308)',
    'F' => 'linear-gradient(135deg, #14b8a6, #0ea5e9)',
    'G' => 'linear-gradient(135deg, #6366f1, #8b5cf6)',
    'H' => 'linear-gradient(135deg, #ef4444, #f43f5e)',
    'I' => 'linear-gradient(135deg, #84cc16, #22c55e)',
    'J' => 'linear-gradient(135deg, #0ea5e9, #3b82f6)',
    'K' => 'linear-gradient(135deg, #d946ef, #8b5cf6)',
    'L' => 'linear-gradient(135deg, #f59e0b, #f97316)',
    'M' => 'linear-gradient(135deg, #06b6d4, #14b8a6)',
    'N' => 'linear-gradient(135deg, #22c55e, #16a34a)',
    'O' => 'linear-gradient(135deg, #ec4899, #f43f5e)',
    'P' => 'linear-gradient(135deg, #3b82f6, #6366f1)',
    'Q' => 'linear-gradient(135deg, #eab308, #f59e0b)',
    'R' => 'linear-gradient(135deg, #10b981, #059669)',
    'S' => 'linear-gradient(135deg, #8b5cf6, #7c3aed)',
    'T' => 'linear-gradient(135deg, #ef4444, #dc2626)',
    'U' => 'linear-gradient(135deg, #0ea5e9, #0284c7)',
    'V' => 'linear-gradient(135deg, #84cc16, #65a30d)',
    'W' => 'linear-gradient(135deg, #f97316, #ea580c)',
    'X' => 'linear-gradient(135deg, #d946ef, #c026d3)',
    'Y' => 'linear-gradient(135deg, #14b8a6, #0f766e)',
    'Z' => 'linear-gradient(135deg, #6366f1, #4338ca)',
];
    public function __construct(protected DatabaseService $database)
    {
    }

    /**
     * @throws DatabaseException
     */
    public function createChatItem(int $customerId, string $status = 'open'): bool|int
    {
        $query = "INSERT INTO chat_item (cid, status) VALUES (:customer_id, :status)";

        $statuses = ['open', 'closed', 'resolved', 'abandon'];

        if (!in_array($status, $statuses)) {
            return false;
        }

        $data = [
            'customer_id' => $customerId,
            'status' => $status
        ];
        if ($this->database->query($query, ...$data)) {
            return $this->database->lastInsertId();
        }
        return false;
    }

    /**
     * @throws DatabaseException
     */
    public function getChatItem(int $id)
    {
        $query = "SELECT * FROM chat_item WHERE id = :id";
         return $this->database->query($query, $id)->fetch();
    }

    /**
     * @throws DatabaseException
     */
    public function getChatItemByCustomerId(int $id): array
    {
        $query = "SELECT * FROM chat_item WHERE cid = :id";
        return $this->database->query($query, $id)->fetchAll();
    }

    public function updateChatItem(int $id, string $status = 'open'): bool
    {
        $query = "UPDATE chat_item SET status = :status WHERE id = :id";
        $statuses = ['open', 'closed', 'resolved', 'abandon'];
        if (!in_array($status, $statuses)) {
            return false;
        }
        $data = [
            'status' => $status,
            'id' => $id
        ];
        if ($this->database->query($query, ...$data)) {
            return true;
        }
        return false;
    }

    /**
     * @throws DatabaseException
     */
    public function deleteChatItem(int $id): bool {
        $query = "DELETE FROM chat_item WHERE id = :id";
        return $this->database->query($query, $id)->rowCount() > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function assignSupportMember(int $chatItemId, int $tid): bool
    {
        $query = "UPDATE chat_item SET tm_id = :tid WHERE id = :id";
        return $this->database->query($query, $tid, $chatItemId)->rowCount() > 0;
    }

    /**
     * @throws DatabaseException
     */
    public function getMemberChatItems(int $chatItemId, int $limit = 3): array
    {
        $query = "SELECT * FROM chat_item WHERE tm_id = :chatItemId ORDER BY updated_at DESC LIMIT :limit";
        return $this->database->query($query, $limit, $chatItemId)->fetchAll();
    }

    /**
     * @throws DatabaseException
     */
    public function getMemberChatItemCount(int $chatItemId): int
    {
        $query = "SELECT COUNT(*) FROM chat_item WHERE tm_id = :chatItemId";
        return $this->database->query($query, $chatItemId)->fetchColumn();
    }

    /**
     * @throws DatabaseException
     */
    public function getChatItemsByAgent(int $id, int $limit = 3): array
    {
        $query = "SELECT * FROM chat_item WHERE tm_id = :id ORDER BY updated_at DESC LIMIT :limit";
        return $this->database->query($query, $id, $limit)->fetchAll();
    }

    /**
     * @throws DatabaseException
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     */
    public function getChatItems(): array
    {
        $query = "SELECT * FROM chat_item ORDER BY updated_at DESC";
        $items = $this->database->query($query)->fetchAll();
        $content = new ChatItemContent($this->database);
        $customer = new Customer($this->database);
        foreach ($items as &$item) {
            $id = $item['id'];
            $contents = $content->getContents($id);
            $customer->load($item['cid']);
            $item['name'] = "{$customer->getFirstName()} {$customer->getLastName()}";
            $firstLetter = substr($customer->getFirstName(), 0, 1);
            $item['color'] = self::alphabetColors[strtoupper($firstLetter)];
            $item['tag']   = $item['status'];
            $item['email'] = $customer->getEmail();
            $item['tickets_count'] = count($contents);
            $item['joined'] = $this->timeAgo($customer->getCreated(),null);

            $initials = substr($customer->getFirstName(), 0, 1).substr($customer->getLastName(), 0, 1);
            $item['initials'] = strtoupper($initials);

            $time = null;
            $flag = false;
            $unread = false;
            $preview = substr($contents[0]['content'] ?? "",0, 50);
            $item['messages'] = array_map(function ($cont) use (&$time, &$flag, &$unread) {
                if (!$flag) {
                    $time = $this->timeAgo($cont['updated_at'], null);
                    $flag = true;
                }

                $date = new DateTime($cont['updated_at']);
                $now = new DateTime();

                $diff = $date->diff($now);

                $minutesPassed = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

                if ($minutesPassed <= 2) {
                   if (!$unread) {
                       $unread = true;
                   }
                }

                return [
                    'sender' => $cont['message_type'] === 'customer' ? 'customer' : 'agent',
                    'text'   => $cont['content'],
                    'time'   => new \DateTime($cont['created_at'])->format('H:i A'),
                ];
            },$contents);
            $item['time'] = $time;
            $item['unread'] = $unread;
            $item['preview'] = $preview;
        }
        return $items;
    }

    /**
     * @throws DateMalformedStringException|DateInvalidTimeZoneException
     */
    private function timeAgo(?string $datetime, ?string $timezone): string
    {
        if (empty($datetime)) {
            return 'unknown';
        }

        if (empty($timezone)) {
            $timezone = "Africa/Blantyre";
        }

        $time = new DateTime($datetime, new \DateTimeZone($timezone))->getTimestamp();
        $now = time();

        if ($time > $now) {
            return 'in the future';
        }

        $diff = $now - $time;

        $units = [
            31536000 => 'year',
            2592000  => 'month',
            604800   => 'week',
            86400    => 'day',
            3600     => 'hour',
            60       => 'minute',
            1        => 'second',
        ];

        foreach ($units as $seconds => $unit) {
            $value = floor($diff / $seconds);
            if ($value >= 1) {
                return $value . ' ' . $unit . ($value > 1 ? 's' : '') . ' ago';
            }
        }

        return 'just now';
    }

}