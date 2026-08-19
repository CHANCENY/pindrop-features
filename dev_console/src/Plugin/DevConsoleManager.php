<?php

namespace Simp\Pindrop\Modules\dev_console\src\Plugin;

use Simp\Pindrop\Plugin\PluginManager;

class DevConsoleManager
{
    public function __construct(protected PluginManager $plugin_manager)
    {
    
    }

    public function getTinkerIncludeScripts(): array
    {
        $plugins = $this->plugin_manager->getEnabledPlugins();
        $scripts = [];

        foreach($plugins as $k=>$plugin) {
            $plugin_path = $plugin['path'] . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'tinker.inc';
           
            if (file_exists($plugin_path)){
                $scripts[] = $plugin_path;
            } 
        }
        return $scripts;
    }
}
