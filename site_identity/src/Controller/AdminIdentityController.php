<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\site_identity\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\site_identity\src\Services\AssetStoreService;
use Simp\Pindrop\Modules\site_identity\src\Services\IconGeneratorService;
use Simp\Pindrop\Modules\site_identity\src\Services\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminIdentityController extends ControllerBase
{
    public function __construct(
        protected SettingsService $settings,
        protected AssetStoreService $assets,
        protected IconGeneratorService $generator
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('site_identity.settings'),
            $container->get('site_identity.assets'),
            $container->get('site_identity.generator'),
        );
    }

    /** GET /admin/site-identity — settings + logo generator, one page. */
    public function index(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig('@site_identity/admin/site_identity/settings.html.twig', [
            'settings' => $this->settings->get(),
            'saved'    => (bool) $request->query->get('saved'),
            'error'    => null,
            'meta'     => ['title' => 'Site Identity — Admin'],
        ]);
    }

    /** POST /admin/site-identity/settings — robots.txt / ads.txt / site name / colors. */
    public function saveSettings(Request $request, string $route_name, array $options): Response
    {
        $data = $request->request->all();
        unset($data['_csrf_token']);

        $this->settings->save([
            'site_name'        => trim((string) ($data['site_name'] ?? 'My Site')) ?: 'My Site',
            'theme_color'      => $this->sanitizeHex($data['theme_color'] ?? '#0a66c2'),
            'background_color' => $this->sanitizeHex($data['background_color'] ?? '#f8f9fb'),
            'robots_txt'       => (string) ($data['robots_txt'] ?? ''),
            'ads_txt'          => (string) ($data['ads_txt'] ?? ''),
            'sitemap_url'      => trim((string) ($data['sitemap_url'] ?? '')) ?: null,
        ]);

        return $this->redirect('/admin/site-identity?saved=1');
    }

    /**
     * POST /admin/site-identity/logo — generate the whole icon set, either
     * from short text (site initials) or an uploaded image.
     */
    public function generateLogo(Request $request, string $route_name, array $options): Response
    {
        $mode = (string) $request->request->get('mode', 'text');

        try {
            if ($mode === 'image') {
                $uploaded = $request->files->get('logo_image');
                if (!$uploaded || !$uploaded->isValid()) {
                    throw new \InvalidArgumentException('Please choose an image to upload.');
                }
                $bytes = file_get_contents($uploaded->getPathname());
                if ($bytes === false) {
                    throw new \InvalidArgumentException('Could not read the uploaded file.');
                }

                $assets = $this->generator->generateFromImage($bytes);
                $this->settings->save(['logo_source' => 'image', 'logo_text' => null]);
            } else {
                $text = trim((string) $request->request->get('logo_text', ''));
                if ($text === '') {
                    throw new \InvalidArgumentException('Enter 1-2 characters for the logo (e.g. site initials).');
                }
                $bgColor = $this->sanitizeHex($request->request->get('logo_bg_color', '#0a66c2'));
                $fgColor = $this->sanitizeHex($request->request->get('logo_fg_color', '#ffffff'));

                $assets = $this->generator->generateFromText($text, $bgColor, $fgColor);
                $this->settings->save(['logo_source' => 'text', 'logo_text' => mb_substr($text, 0, 4)]);
            }

            $this->assets->putMany($assets);
        } catch (\Throwable $e) {
            return $this->renderTwig('@site_identity/admin/site_identity/settings.html.twig', [
                'settings' => $this->settings->get(),
                'saved'    => false,
                'error'    => $e->getMessage(),
                'meta'     => ['title' => 'Site Identity — Admin'],
            ], 422);
        }

        return $this->redirect('/admin/site-identity?saved=1');
    }

    private function sanitizeHex(mixed $value): string
    {
        $value = trim((string) $value);
        return preg_match('/^#[0-9a-fA-F]{3,6}$/', $value) ? $value : '#0a66c2';
    }
}
