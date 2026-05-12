<?php

namespace Simp\Pindrop\Modules\admin\src\Plugin;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Settings\SettingsInterface;
use Symfony\Component\HttpFoundation\Request;

class AdminSettings implements SettingsInterface
{

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function formBuild(Request $request, \Simp\Pindrop\Settings\Setting|null $setting): string
    {
       return \getAppContainer()->get('twig')->render('@admin/settings/form.html.twig', 
       is_null($setting) ? [] : $setting?->getValue());
    }

    public function savableValues(Request $request): array
    {
        return [
            'site_name' => $request->request->get('site_name'),
            'page_not_error' => $request->request->get('page_not_error'),
            'forbidden_error' => $request->request->get('forbidden_error'),
        ];
    }

    public function settingKey(): string
    {
        return "admin.settings";
    }
}