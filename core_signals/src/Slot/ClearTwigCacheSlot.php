<?php

namespace Simp\Pindrop\Modules\core_signals\src\Slot;

use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;

/**
 * Deletes all compiled Twig templates from var/cache/twig/.
 * Connect to plugin.installed, plugin.uninstalled, or entity.updated
 * to keep rendered output fresh after structural changes.
 */
class ClearTwigCacheSlot implements SlotInterface
{
    public function handle(array $payload): void
    {
        $cacheDir = defined('APP_ROOT')
            ? APP_ROOT . '/var/cache/twig'
            : dirname(__DIR__, 6) . '/var/cache/twig';

        $this->deleteDirectory($cacheDir);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
    }
}
