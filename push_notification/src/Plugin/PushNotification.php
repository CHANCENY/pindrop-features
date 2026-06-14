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

    public function __construct(
        protected DatabaseService $databaseService,
        protected PushNotificationService $pushNotificationService
    ) {
        $this->pushNotificationSettings = \getAppContainer()->get(Settings::class)
            ->getSetting('push_notification.settings');
    }

    public function pushNotification(array $notification): bool
    {
        $uid = $notification['uid'] ?? null;
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

        $data = ['uid' => $uid];

        if (isset($notification['title'])) {
            $data['title'] = $notification['title'];
        }

        if (isset($notification['body'])) {
            $data['body'] = $notification['body'];
        }

        if (isset($notification['url'])) {
            $data['url'] = $notification['url'];
        } else {
            $data['url'] = $this->pushNotificationSettings?->get('url') ?? '';
        }

        $settings          = $this->pushNotificationSettings->getValue();
        $data['settings']  = $settings;

        $insertId = $this->databaseService->table('push_notifications')->insert([
            'uid'          => $uid,
            'content_json' => json_encode($data),
        ]);

        if ($insertId > 0) {
            $auth = [
                'VAPID' => [
                    'subject'    => 'mailto:' . $user->getEmail(),
                    'publicKey'  => $notifyUserSettings['public_key'],
                    'privateKey' => $notifyUserSettings['private_key'],
                ],
            ];

            $webPush = new WebPush($auth);

            $webPush->sendOneNotification(
                Subscription::create(json_decode($notifyUserSettings['google_json'], true)),
                json_encode($data),
                ['TTL' => 5000]
            );
        }

        return true;
    }

    public static function factory(): PushNotification
    {
        return new static(
            \getAppContainer()->get('database'),
            \getAppContainer()->get('push_notification.service')
        );
    }
}
