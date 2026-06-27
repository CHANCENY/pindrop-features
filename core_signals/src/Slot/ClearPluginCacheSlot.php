<?php

namespace Simp\Pindrop\Modules\core_signals\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Deletes the plugin manifest cache under var/cache/plugins/.
 * Connect to plugin.installed or plugin.uninstalled so that the next
 * request forces PluginManager to rebuild from YAML sources.
 */
class ClearPluginCacheSlot implements SlotInterface
{
    public function handle(array $payload): void
    {
        $cacheDir = defined('APP_ROOT')
            ? APP_ROOT . '/var/cache/plugins'
            : dirname(__DIR__, 6) . '/var/cache/plugins';

        if (!is_dir($cacheDir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
    }
}
