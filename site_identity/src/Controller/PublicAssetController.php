<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\site_identity\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\site_identity\src\Services\AssetStoreService;
use Simp\Pindrop\Modules\site_identity\src\Services\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicAssetController extends ControllerBase
{
    public function __construct(
        protected SettingsService $settings,
        protected AssetStoreService $assets
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('site_identity.settings'),
            $container->get('site_identity.assets'),
        );
    }

    /** GET /robots.txt */
    public function robots(Request $request, string $route_name, array $options): Response
    {
        $settings = $this->settings->get();
        $body = rtrim($this->settings->robotsTxt());

        $sitemapUrl = trim((string) ($settings['sitemap_url'] ?? ''));
        if ($sitemapUrl !== '') {
            if (!str_starts_with($sitemapUrl, 'http')) {
                $sitemapUrl = rtrim($request->getSchemeAndHttpHost(), '/') . '/' . ltrim($sitemapUrl, '/');
            }
            $body .= "\n\nSitemap: {$sitemapUrl}\n";
        } else {
            $body .= "\n";
        }

        return $this->render($body, 200, ['Content-Type' => 'text/plain']);
    }

    /** GET /ads.txt */
    public function ads(Request $request, string $route_name, array $options): Response
    {
        $body = $this->settings->adsTxt();

        if (trim($body) === '') {
            return $this->render('', 404, ['Content-Type' => 'text/plain']);
        }

        return $this->render($body, 200, ['Content-Type' => 'text/plain']);
    }

    /** GET /site.webmanifest */
    public function manifest(Request $request, string $route_name, array $options): Response
    {
        $settings = $this->settings->get();
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');

        $manifest = [
            'name'             => $settings['site_name'],
            'short_name'       => mb_substr($settings['site_name'], 0, 12),
            'start_url'        => $baseUrl . '/',
            'scope'            => $baseUrl . '/',
            'display'          => 'standalone',
            'background_color' => $settings['background_color'],
            'theme_color'      => $settings['theme_color'],
            'icons' => [
                ['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
                ['src' => '/favicon-96x96.png', 'sizes' => '96x96', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/icons/512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ];

        return $this->json($manifest, 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    /** GET /favicon.svg | /favicon.ico | /favicon-96x96.png | /apple-touch-icon.png */
    public function servedVariant(Request $request, string $route_name, array $options): Response
    {
        $variant = match ($route_name) {
            'site_identity.favicon.svg'   => 'favicon.svg',
            'site_identity.favicon.ico'   => 'favicon.ico',
            'site_identity.favicon.96'    => 'favicon-96x96.png',
            'site_identity.apple_touch'   => 'apple-touch-icon.png',
            default => null,
        };

        return $this->serveVariantOrDefault($variant, $route_name);
    }

    /** GET /icons/{size}.png — 192 or 512, used by the manifest. */
    public function sizedIcon(Request $request, string $route_name, array $options): Response
    {
        $size = (string) $request->query->get('size');
        $variant = match ($size) {
            '192' => 'icon-192.png',
            '512' => 'icon-512.png',
            default => null,
        };

        if ($variant === null) {
            return $this->render('Not found', 404);
        }

        return $this->serveVariantOrDefault($variant, $route_name);
    }

    /**
     * Serves the admin-generated asset if one exists in the DB; otherwise
     * falls back to a built-in default so a fresh install never shows a
     * broken favicon before an admin has generated one.
     */
    private function serveVariantOrDefault(?string $variant, string $routeName): Response
    {
        if ($variant === null) {
            return $this->render('Not found', 404);
        }

        $asset = $this->assets->get($variant);

        if ($asset) {
            return $this->render($asset['data'], 200, [
                'Content-Type'  => $asset['mime_type'],
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        return $this->defaultAsset($variant);
    }

    /** Built-in placeholder set so favicon requests never 404 on a fresh install. */
    private function defaultAsset(string $variant): Response
    {
        if ($variant === 'favicon.svg') {
            $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <rect width="64" height="64" rx="14" fill="#0a66c2"/>
  <text x="32" y="42" font-family="Arial, Helvetica, sans-serif" font-size="30" font-weight="700"
        text-anchor="middle" fill="#ffffff">S</text>
</svg>
SVG;
            return $this->render($svg, 200, ['Content-Type' => 'image/svg+xml']);
        }

        // For raster variants with no generated asset yet, redirect to the
        // SVG rather than shipping a second hand-built PNG encoder path.
        return $this->redirect('/favicon.svg');
    }
}
