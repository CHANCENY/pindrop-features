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
        if (!$this->database_service->tableExists('push_notification_user_allowed')){
            throw new \Exception('Push notification settings store not found',230);
        }
    }

    public function addBrowserEnabled(array $data, ?int $user_id = null){
        $uid = empty($user_id) ? $this->current_user?->id() : $user_id;

        $query = "select * from push_notification_user_allowed where user_id = :uid";
        $results = $this->database_service->query($query, $uid)->fetch();
        if (empty($results)){
            return false;
        }

        $data = json_encode($data);

        $query = "update push_notification_user_allowed set google_json = :data where user_id = :uid";
        return $this->database_service->query($query, $data, $uid)->rowCount() > 0;

    }

    public function createVapidToken(?int $user_id = null): array|bool {
        $tokens = VAPID::createVapidKeys();

        // Save the Vapid
        $uid = empty($user_id) ? $this->current_user?->id() : $user_id;

        $query = "select * from push_notification_user_allowed where user_id = :uid";
        $results = $this->database_service->query($query,$uid)->fetch();
        if (!empty($results)) {
            return [
                'publicKey' => $results['public_key'],
                'privateKey' => $results['private_key'],
            ];
        }

        $query = "INSERT INTO `push_notification_user_allowed` (user_id, public_key, private_key) VALUES (:user_id, :public_key, :private_key)";
        $result = $this->database_service->query($query, ...$i=[
            'user_id' => $uid,
            'public_key' => $tokens['publicKey'],
            'private_key' => $tokens['privateKey'],
        ])->rowCount() > 0;
        return $result ? $tokens : false;
    }

    public function getUserSetting(int $uid): array|bool {
        $query = "select * from push_notification_user_allowed where user_id = :uid";
        return $this->database_service->query($query, $uid)->fetch();
    }
}
