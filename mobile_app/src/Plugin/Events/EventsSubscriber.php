<?php

namespace Simp\Pindrop\Modules\mobile_app\src\Plugin\Events;

use Simp\Pindrop\Events\EventEmitter;
use Simp\Pindrop\Events\EventsSubscriberInterface;
use Simp\Pindrop\Events\SystemEvents\Events;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\mobile_app\src\Plugin\Events\Events as PluginEvents;
use Simp\Pindrop\Theme\ThemeManager;

class EventsSubscriber implements EventsSubscriberInterface
{

    protected ThemeManager $theme_manager;
    public function __construct()
    {
        $this->theme_manager = getAppContainer()->get("theme.manager");
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::PLUGIN_INSTALLED => [$this, "mobilePluginInstalled"],
            Events::PLUGIN_UNINSTALLED => [$this,"mobilePluginUninstalled"],
            PluginEvents::MOBILE_ACTIVATED => [$this, "mobilePluginActivated"]
        ];
    }

    public function mobilePluginInstalled(EventEmitter $event) {
        $admin_theme = $this->theme_manager->getTheme('admin');
        $container = getAppContainer();
        $pluginId = $event->plugin_id;
       
        if ($pluginId === "mobile_app") {

            $filename = $container->get("CONFIG") . DIRECTORY_SEPARATOR . "/mobile.settings.yml";
            if (!file_exists($filename)) {
                touch($filename);
            }

            $default = __DIR__ . "/default/setting.yml";

            if (file_exists($default)) {
                copy($default, $filename);
            }

            $theme_dir_zip = __DIR__ . "/default/mobile.zip";

            $theme_dir_dest = realpath(__DIR__ . "/../../../../../themes");

            exec("rm -rf $theme_dir_dest/mobile");
            //exec("mkdir $theme_dir_dest/mobile");

            $unzipBinary = exec("which unzip");
            if ($unzipBinary) {
                exec("$unzipBinary $theme_dir_zip -d $theme_dir_dest");
            }

        }
    }

    public function mobilePluginUninstalled(EventEmitter $event) {
       $admin_theme = $this->theme_manager->getTheme('admin');
    }

    public function mobilePluginActivated(EventEmitter $event) {
        
    }

}