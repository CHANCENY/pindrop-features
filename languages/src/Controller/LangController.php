<?php

namespace Simp\Pindrop\Modules\languages\src\Controller;

use Simp\Pindrop\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class LangController extends ControllerBase
{
    public function switchLanguage(Request $request, string $route_name, array $options): JsonResponse
    {
        $lang = json_decode($request->getContent(), true)['lang'] ?? "en";
        if (getAppContainer()->has('language.support.service')) {
            getAppContainer()->get('language.support.service')->setDefaultLanguage($lang);
        }
        $path = json_decode($request->getContent(), true)['path'] ?? "";
        $newPath = $path;
        if ($path) {
            $parts = explode("/", trim($path, "/")) ?? [];
            $firstPath = $parts[0] ?? "";
            if (getAppContainer()->get('language.support.service')->getLanguage($firstPath)) {
                array_shift($parts);
                $parts = [$lang, ...$parts];
                $newPath = "/". implode("/", $parts);
            }
            else {
                $parts = [$lang, ...$parts];
                $newPath = "/". implode("/", $parts);
            }
        }

        return new JsonResponse(['success' => true, 'lang' =>
            getAppContainer()->get('language.support.service')->getLanguage($lang),
            'path' => $newPath
        ]);
    }

    public function language(Request $request, string $route_name, array $options): JsonResponse
    {
        $lang = getAppContainer()->get('language.support.service')->getDefaultLanguage();
        return new JsonResponse(['success' => true, 'lang' => $lang]);
    }
}