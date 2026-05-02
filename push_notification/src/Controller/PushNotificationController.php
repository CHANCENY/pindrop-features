<?php

namespace Simp\Pindrop\Modules\push_notification\src\Controller;


use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\push_notification\src\Plugin\PushNotification;
use Simp\Pindrop\Modules\push_notification\src\Plugin\PushNotificationService;
use Simp\Pindrop\Settings\Settings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PushNotificationController extends ControllerBase
{


    public function __construct(protected PushNotificationService $notificationService, protected Settings $settings)
    {
        return parent::__construct();
    }

    public static function create(ContainerInterface $container): PushNotificationController
    {
        return new static(
            $container->get('push_notification.service'),
            $container->get(Settings::class),
        );
    }

    public function tokenGenerator(Request $request, string $route_name, array $options)
    {
        $tokens = $this->notificationService->createVapidToken();
        if ($tokens) {
            return new JsonResponse(['publicKey' => $tokens['publicKey']]);
        }
        return new JsonResponse([]);
    }

    public function collectConfiguration(Request $request, string $route_name, array $options)
    {
        $settings = $this->settings->getSetting('push_notification.settings')?->getValue() ?? [];
        $settings['is_login'] = !empty(\getAppContainer()->get('current_user'));
        $settings['subscribe_link'] = '/modules/push_notification/subscribe';
        $settings['is_subscribed']  = !empty($this->notificationService->getUserSetting(\getAppContainer()->get('current_user')?->id()));
        return new JsonResponse($settings);

    }

    public function saveEnabledBrowser(Request $request, string $route_name, array $options)
    {
        $data = json_decode($request->getContent(), true);
        $result = $this->notificationService->addBrowserEnabled($data);
        return new JsonResponse(['status' => $result]);
    }

    public function testing(Request $request, string $route_name, array $options)
    {

        $results = PushNotification::factory()->pushNotification([
            'title' => 'Testing push notification',
            'body' => "This is notification sent as notification",
            'url' => $request->getSchemeAndHttpHost(),
            'uid' => \getAppContainer()->get('current_user')->id()
        ]);

        return $this->redirect('/');
    }
}
