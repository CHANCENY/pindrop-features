<?php

namespace Simp\Pindrop\Modules\music_cron\src\Routes;

use DI\Container;
use Override;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\music_cron\src\Service\SpotifyAlbum;
use Simp\Pindrop\Routing\AttributeRoute;
use Symfony\Component\HttpFoundation\Request;

class MusicCronController extends ControllerBase
{
    
    public function __construct(protected SpotifyAlbum $spotifyAlbum)
    {
        return parent::__construct();
    }


    public static function create(Container $container): static
    {
        return new static($container->get('music_cron.downloader'));
    }


    #[AttributeRoute("/admin/music/spotify/downloads", ['GET', 'POST'], permission: [])]
    public function index(Request $request, string $route_name, array $options)
    {

        $lists = $this->spotifyAlbum->downloadSpotifyProgressStatus();

        return $this->renderTwig("@music_cron/admin/albums.download.html.twig",[
            'lists' => $lists
        ]);
    }

    #[AttributeRoute("/admin/music/spotify/downloads/new", ['GET', 'POST'], permission: [])]
    public function addAlbum(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $links = $request->request->get('links',"");
            $links = explode("\n", $links);
            
            foreach($links as $link) {
                $link = trim($link);
                $this->spotifyAlbum->addSpotifyAlbum($link);
            }

            return $this->redirect('/admin/music/spotify/downloads');
        }
        return $this->renderTwig("@music_cron/admin/albums.download.create.html.twig",[
            
        ]);
    }
}
