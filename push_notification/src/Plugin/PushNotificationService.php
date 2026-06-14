<?php

namespace Simp\Pindrop\Modules\push_notification\src\Plugin;

use Minishlink\WebPush\VAPID;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\CurrentUser;

class PushNotificationService
{
    protected ?CurrentUser $current_user;

    public function __construct(protected DatabaseService $database_service)
    {
        $this->current_user = \getAppContainer()->get('current_user');
    }

    public function addBrowserEnabled(array $data, ?int $user_id = null): bool
    {
        $uid = empty($user_id) ? $this->current_user?->id() : $user_id;

        $exists = $this->database_service->table('push_notification_user_allowed')
            ->where('user_id', '=', $uid)
            ->exists();

        if (!$exists) {
            return false;
        }

        return $this->database_service->table('push_notification_user_allowed')
            ->where('user_id', '=', $uid)
            ->update(['google_json' => json_encode($data)]) > 0;
    }

    public function createVapidToken(?int $user_id = null): array|bool
    {
        $tokens = VAPID::createVapidKeys();
        $uid    = empty($user_id) ? $this->current_user?->id() : $user_id;

        $existing = $this->database_service->table('push_notification_user_allowed')
            ->where('user_id', '=', $uid)
            ->first();

        if (!empty($existing)) {
            return [
                'publicKey'  => $existing['public_key'],
                'privateKey' => $existing['private_key'],
            ];
        }

        $insertId = $this->database_service->table('push_notification_user_allowed')->insert([
            'user_id'     => $uid,
            'public_key'  => $tokens['publicKey'],
            'private_key' => $tokens['privateKey'],
        ]);

        return $insertId > 0 ? $tokens : false;
    }

    public function getUserSetting(?int $uid): array|bool
    {
        $row = $this->database_service->table('push_notification_user_allowed')
            ->where('user_id', '=', $uid)
            ->first();

        return $row ?: false;
    }
}
