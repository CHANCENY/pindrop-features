<?php

namespace Simp\Pindrop\Modules\push_notification\src\Setting;

use Simp\Pindrop\FileSystem\FileSystem;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Settings\Settings;
use Simp\Pindrop\Settings\SettingsInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class PushNotificationSetting implements SettingsInterface
{
    public function formBuild(Request $request, \Simp\Pindrop\Settings\Setting|null $setting): string
    {
         $_SESSION['is_icon_save'] = false;
        return \getAppContainer()->get('twig')->render('@push_notification/settings/notify.setting.html.twig', is_null($setting) ? [] : $setting?->getValue());
    }

    public function savableValues(Request $request): array
    {
        try {
            if ($request->isMethod('POST')) {
                $files = $request->files->all();
                $icon = $files['icon_path'] ?? null;
                $pdf  = $files['subscribe_tcs'] ?? null;

                if ($icon instanceof UploadedFile) {
                    $iconPath = "public://icon";
                    if (!is_dir($iconPath)) {
                        mkdir($iconPath, 0777, true);
                    }

                    $name = "/icon." . $icon->getClientOriginalExtension();
                   
                    $max_size = 1000000;
                    if ($icon->getSize() > $max_size) {
                        Message::error("Icon file size exceed 1MB");
                    } else {

                        /**
                         * @var FileSystem
                         */
                        $fileSystem = \getAppContainer()->get("filesystem");

                        $allowed_types = ["image/png"];
                        if (!in_array($icon->getMimeType(), $allowed_types)) {
                            Message::error("Icon file type not allowed. Only png file is allowed");
                        } 
                        else {
                            
                            $file = $fileSystem->copy($icon->getRealPath(), $iconPath . $name);
                            if ($file) {
                                $uri = $fileSystem->getPublicUrl($iconPath. $name);
                                $request->request->set('icon', $uri);
                                $_SESSION['is_icon_save'] = true;
                            }
                        }
                    }
                }

                if ($pdf instanceof UploadedFile) {
                    $pdfFile = "public://tcs";
                    if (!is_dir($pdfFile)) {
                        mkdir($pdfFile, 0777, true);
                    }
                    $name = "tcs". $pdf->getClientOriginalExtension();
                    $max_size = 5000000;
                    if ($pdf->getSize() > $max_size) {
                        Message::error("TCS file has exceed max size");
                    }
                    else {
                         /**
                         * @var FileSystem
                         */
                        $fileSystem = \getAppContainer()->get("filesystem");

                        $allowed_types = ["application/pdf"];
                        if (!in_array($pdf->getMimeType(), $allowed_types)) {
                            Message::error("TCS file type not allowed. Only png file is allowed");
                        } 
                        else {
                            
                            $file = $fileSystem->copy($pdf->getRealPath(), $pdfFile . $name);
                            if ($file) {
                                $uri = $fileSystem->getPublicUrl($pdfFile. $name);
                                $request->request->set('tcs', $uri);
                            
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            
        }
        $oldSettings = \getAppContainer()->get(Settings::class);
        $oldIcon = $oldSettings->getSetting($this->settingKey())?->get('icon_path') ?? null;

        return [
            'is_enabled' => $request->request->get('is_enabled'),
            'icon_path'  => $request->request->get('icon',$oldIcon),
            'title'      => $request->request->get('title'),
            'url'        => $request->request->get('url'),
            'push_sound' => $request->request->get('push_sound'),
            'vibration'  => $request->request->get('vibration'),
            'subscribe_tcs' => $request->request->get('tcs'),
        ];
    }

    public function settingKey(): string
    {
        return "push_notification.settings";
    }
}
