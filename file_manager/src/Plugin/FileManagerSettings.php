<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\file_manager\src\Plugin;

use Simp\Pindrop\Modules\file_manager\src\Services\FileManagerService;
use Simp\Pindrop\Modules\file_manager\src\Services\RequestGuard;
use Simp\Pindrop\Settings\Setting;
use Simp\Pindrop\Settings\Settings;
use Simp\Pindrop\Settings\SettingsInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Settings panel (Admin > Settings) + resolver that turns stored settings into
 * the arguments FileManagerService needs.
 *
 * NOTE: the CMS settings page posts every plugin's fields together with no
 * prefix, so all field names here start with "fm_" to avoid collisions.
 */
class FileManagerSettings implements SettingsInterface
{
    public const KEY = 'file_manager.settings';

    private const PHP_FAMILY = ['php', 'phtml', 'phar', 'pht', 'php3', 'php4', 'php5', 'php7', 'phps'];

    public function settingKey(): string
    {
        return self::KEY;
    }

    public function formBuild(Request $request, ?Setting $setting): string
    {
        $values = $setting?->getValue() ?? [];
        $defaults = FileManagerService::DEFAULTS;
        return getAppContainer()->get('twig')->render('@file_manager/settings/form.html.twig', [
            'fm' => [
                'root_path'          => (string) ($values['root_path'] ?? ''),
                'protected_paths'    => (string) ($values['protected_paths'] ?? implode("\n", $defaults['protected_paths'])),
                'blocked_extensions' => (string) ($values['blocked_extensions'] ?? implode(', ', $defaults['blocked_extensions'])),
                'allow_php'          => !empty($values['allow_php']),
                'read_only'          => !empty($values['read_only']),
                'show_hidden'        => !array_key_exists('show_hidden', $values) || !empty($values['show_hidden']),
                'max_edit_kb'        => (int) ($values['max_edit_kb'] ?? $defaults['max_edit_bytes'] / 1024),
                'max_upload_mb'      => (int) ($values['max_upload_mb'] ?? $defaults['max_upload_bytes'] / 1048576),
            ],
            'project_root' => self::projectRoot(),
        ]);
    }

    public function savableValues(Request $request): array
    {
        // The settings page can be submitted by anyone who may administer settings.
        // Only holders of can_use_file_manager may change THIS plugin's settings;
        // for everyone else keep whatever is stored.
        try {
            RequestGuard::requireUser();
        } catch (\Throwable) {
            return self::stored();
        }

        $in = $request->request;
        return [
            'root_path'          => trim((string) $in->get('fm_root_path', '')),
            'protected_paths'    => trim((string) $in->get('fm_protected_paths', '')),
            'blocked_extensions' => trim((string) $in->get('fm_blocked_extensions', '')),
            'allow_php'          => $in->get('fm_allow_php') ? 1 : 0,
            'read_only'          => $in->get('fm_read_only') ? 1 : 0,
            'show_hidden'        => $in->get('fm_show_hidden') ? 1 : 0,
            'max_edit_kb'        => max(16, min(20480, (int) $in->get('fm_max_edit_kb', 2048))),
            'max_upload_mb'      => max(1, min(2048, (int) $in->get('fm_max_upload_mb', 50))),
        ];
    }

    // ------------------------------------------------------------------

    /** Absolute path of the CMS project root (…/modules/file_manager/src/Plugin → 4 levels up). */
    public static function projectRoot(): string
    {
        return (string) (realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
    }

    public static function stored(): array
    {
        try {
            $setting = getAppContainer()->get(Settings::class)->getSetting(self::KEY);
            return $setting ? $setting->getValue() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{root:string, options:array, show_hidden:bool, warning:?string}
     */
    public static function resolve(): array
    {
        $v = self::stored();
        $warning = null;

        // Root folder: blank = project root; relative = under project root; absolute allowed.
        $project = self::projectRoot();
        $configured = trim((string) ($v['root_path'] ?? ''));
        $root = $project;
        if ($configured !== '') {
            $candidate = (str_starts_with($configured, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $configured))
                ? $configured
                : $project . '/' . ltrim($configured, '/\\');
            $real = realpath($candidate);
            if ($real !== false && is_dir($real) && str_replace('\\', '/', $real) !== '/') {
                $root = $real;
            } else {
                $warning = 'The configured root folder "' . $configured . '" is not a usable directory. Using the project root instead.';
            }
        }

        $blocked = self::splitList((string) ($v['blocked_extensions'] ?? implode(',', FileManagerService::DEFAULTS['blocked_extensions'])));
        if (!empty($v['allow_php'])) {
            $blocked = array_values(array_diff($blocked, self::PHP_FAMILY));
        }
        $protectedRaw = array_key_exists('protected_paths', $v)
            ? (string) $v['protected_paths']
            : implode("\n", FileManagerService::DEFAULTS['protected_paths']);

        return [
            'root'        => $root,
            'options'     => [
                'protected_paths'    => self::splitList($protectedRaw),
                'blocked_extensions' => $blocked,
                'read_only'          => !empty($v['read_only']),
                'max_edit_bytes'     => max(16, (int) ($v['max_edit_kb'] ?? 2048)) * 1024,
                'max_upload_bytes'   => max(1, (int) ($v['max_upload_mb'] ?? 50)) * 1048576,
            ],
            'show_hidden' => !array_key_exists('show_hidden', $v) || !empty($v['show_hidden']),
            'warning'     => $warning,
        ];
    }

    private static function splitList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw) ?: []), fn($s) => $s !== ''));
    }
}
