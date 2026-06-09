<?php

namespace Simp\Pindrop\Modules\chat_window\src\Chat;

use DateTime;
use DateTimeZone;
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

    public function createChatItem(int $customerId, string $status = 'open'): bool|int
    {
        $allowed = ['open', 'closed', 'resolved', 'abandon'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        return $this->database->table('chat_item')->insert([
            'cid'    => $customerId,
            'status' => $status,
        ]);
    }

    public function getChatItem(int $id): ?array
    {
        return $this->database->table('chat_item')
            ->where('id', '=', $id)
            ->first();
    }

    public function getChatItemByCustomerId(int $id): array
    {
        return $this->database->table('chat_item')
            ->where('cid', '=', $id)
            ->get();
    }

    public function updateChatItem(int $id, string $status = 'open'): bool
    {
        $allowed = ['open', 'closed', 'resolved', 'abandon'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        return (bool) $this->database->table('chat_item')
            ->where('id', '=', $id)
            ->update(['status' => $status]);
    }

    public function deleteChatItem(int $id): bool
    {
        return $this->database->table('chat_item')
            ->where('id', '=', $id)
            ->delete() > 0;
    }

    public function assignSupportMember(int $chatItemId, int $tid): bool
    {
        return (bool) $this->database->table('chat_item')
            ->where('id', '=', $chatItemId)
            ->update(['tm_id' => $tid]);
    }

    public function getMemberChatItems(int $agentId, int $limit = 3): array
    {
        return $this->database->table('chat_item')
            ->where('tm_id', '=', $agentId)
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    public function getMemberChatItemCount(int $agentId): int
    {
        return $this->database->table('chat_item')
            ->where('tm_id', '=', $agentId)
            ->count();
    }

    public function getChatItemsByAgent(int $agentId, int $limit = 3): array
    {
        return $this->database->table('chat_item')
            ->where('tm_id', '=', $agentId)
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    public function getChatItems(): array
    {
        $items    = $this->database->table('chat_item')
            ->latest('updated_at')
            ->get();
        $content  = new ChatItemContent($this->database);
        $customer = new Customer($this->database);

        foreach ($items as &$item) {
            $contents = $content->getContents($item['id']);
            $customer->load($item['cid']);

            $item['name']          = "{$customer->getFirstName()} {$customer->getLastName()}";
            $firstLetter           = substr($customer->getFirstName(), 0, 1);
            $item['color']         = self::alphabetColors[strtoupper($firstLetter)] ?? '';
            $item['tag']           = $item['status'];
            $item['email']         = $customer->getEmail();
            $item['tickets_count'] = count($contents);
            $item['joined']        = $this->timeAgo($customer->getCreated(), null);
            $item['initials']      = strtoupper(
                substr($customer->getFirstName(), 0, 1) . substr($customer->getLastName(), 0, 1)
            );

            $time    = null;
            $flag    = false;
            $unread  = false;
            $preview = substr($contents[0]['content'] ?? '', 0, 50);

            $item['messages'] = array_map(function ($cont) use (&$time, &$flag, &$unread) {
                if (!$flag) {
                    $time = $this->timeAgo($cont['updated_at'], null);
                    $flag = true;
                }
                $date          = new DateTime($cont['updated_at']);
                $now           = new DateTime();
                $diff          = $date->diff($now);
                $minutesPassed = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
                if ($minutesPassed <= 2 && !$unread) {
                    $unread = true;
                }
                return [
                    'sender' => $cont['message_type'] === 'customer' ? 'customer' : 'agent',
                    'text'   => $cont['content'],
                    'time'   => (new DateTime($cont['created_at']))->format('H:i A'),
                ];
            }, $contents);

            $item['time']    = $time;
            $item['unread']  = $unread;
            $item['preview'] = $preview;
        }

        return $items;
    }

    private function timeAgo(?string $datetime, ?string $timezone): string
    {
        if (empty($datetime)) {
            return 'unknown';
        }
        $timezone = $timezone ?: 'Africa/Blantyre';
        $time     = (new DateTime($datetime, new DateTimeZone($timezone)))->getTimestamp();
        $now      = time();
        if ($time > $now) return 'in the future';
        $diff  = $now - $time;
        $units = [
            31536000 => 'year',   2592000 => 'month', 604800 => 'week',
            86400    => 'day',    3600    => 'hour',   60     => 'minute',
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
