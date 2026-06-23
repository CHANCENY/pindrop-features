<?php

namespace Simp\Pindrop\Modules\audit_log\src\Plugin;

use Simp\Pindrop\Settings\Setting;
use Simp\Pindrop\Settings\SettingsInterface;
use Symfony\Component\HttpFoundation\Request;

class AuditLogSettings implements SettingsInterface
{
    public function settingKey(): string
    {
        return 'audit_log.settings';
    }

    public function formBuild(Request $request, ?Setting $setting): string
    {
        return \getAppContainer()
            ->get('twig')
            ->render('@audit_log/settings/form_audit.html.twig', [
                'retention_days' => $setting?->get('retention_days') ?? 90,
                'enabled'        => $setting?->get('enabled')         ?? true,
            ]);
    }

    public function savableValues(Request $request): array
    {
        return [
            'retention_days' => max(1, (int) $request->request->get('retention_days', 90)),
            'enabled'        => (bool) $request->request->get('enabled', true),
        ];
    }
}
