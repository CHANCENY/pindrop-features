<?php

namespace Simp\Pindrop\Modules\push_notification\src\Plugin;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Settings\Setting;
use Simp\Pindrop\Settings\Settings;

class PushNotification
{
    protected ?Setting $pushNotificationSettings = null;

    public function __construct(protected DatabaseService $databaseService, protected PushNotificationService $pushNotificationService)
    {
        if (!$this->databaseService->tableExists("push_notifications")) {
            throw new \Exception("Storage not defined");
        }

        $this->pushNotificationSettings = \getAppContainer()->get(Settings::class)->getSetting('push_notification.settings');
    }

    public function pushNotification(array $notification): bool
    {
        $uid = $notification["uid"] ?? null;
        if (!$uid) {
            return false;
        }

        $user = User::loadById($uid, $this->databaseService);
        if (!$user) {
            return false;
        }

        $notifyUserSettings = $this->pushNotificationService->getUserSetting($user->getId());
        if (!$notifyUserSettings) {
            return false;
        }

        $query = "insert into push_notifications (uid, content_json) values (:uid, :data)";
        $data = [
            'uid' => $uid,
        ];

        if (isset($notification["title"])) {
            $data["title"] = $notification["title"];
        }

        if (isset($notification["body"])) {
            $data["body"] = $notification["body"];
        }


        if (isset($notification["url"])) {
            $data["url"] = $notification["url"];
        } else {
            $data["url"] = $this->pushNotificationSettings?->get('url') ?? "";
        }

        $settings =  $this->pushNotificationSettings->getValue();
        
        $data['settings'] = $settings;

        $st = $this->databaseService->query($query,...$i=['data'=> json_encode($data), 'uid'=>$uid]);
        if ($this->databaseService->lastInsertId() > 0) {

        

            $auth = [
                'VAPID' => [
                    'subject' => 'mailto:'. $user->getEmail(),
                    'publicKey' => $notifyUserSettings['public_key'],
                    'privateKey' => $notifyUserSettings['private_key'],
                ],
            ];

            $webPush = new WebPush($auth);

            $report = $webPush->sendOneNotification(
                Subscription::create(json_decode($notifyUserSettings['google_json'], true)),
                json_encode($data),
                ['TTL' => 5000]
            );
        }

        return true;
    }

    public static function factory(): PushNotification {
        return new static(\getAppContainer()->get('database'),\getAppContainer()->get('push_notification.service'));
    }
}
