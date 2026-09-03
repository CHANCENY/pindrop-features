<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\music\src\Services\AlbumService;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\MediaUrlService;
use Simp\Pindrop\Modules\music\src\Services\TrackService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ModerationController
 *
 * Everything here is gated behind can_music_moderate (routing.yml). Report
 * *submission* (a regular logged-in user flagging a track/artist) isn't
 * built here — it lands in Phase 6 alongside likes/follows, since it's a
 * general user action rather than a moderation one; this controller only
 * covers the staff side (reviewing/resolving what's already been reported).
 */
class ModerationController extends ControllerBase
{
    public function __construct(
        protected TrackService $tracks,
        protected ArtistService $artists,
        protected AlbumService $albums,
        protected MediaUrlService $mediaUrl,
        protected DatabaseService $database
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.track'),
            $container->get('music.artist'),
            $container->get('music.album'),
            $container->get('music.media_url'),
            $container->get('database'),
        );
    }

    /** GET /admin/music */
    public function dashboard(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig('@music/admin/dashboard.html.twig', [
            'total_artists'   => $this->database->table('music_artists')->where('status', '=', 'active')->count(),
            'total_albums'    => $this->database->table('music_albums')->where('status', '=', 'published')->count(),
            'total_tracks'    => $this->database->table('music_tracks')->where('status', '=', 'published')->count(),
            'pending_reports' => $this->database->table('music_reports')->where('status', '=', 'pending')->count(),
            'recent_tracks'   => $this->tracks->recentlyAdded(10),
            'meta'            => ['title' => 'Music Admin Dashboard'],
        ]);
    }

    /** GET /admin/music/tracks */
    public function tracks(Request $request, string $route_name, array $options): Response
    {
        $status = (string) $request->query->get('status', 'published');
        $page = max(1, (int) $request->query->get('page', 1));

        $tracks = $this->database->table('music_tracks')
            ->where('status', '=', $status)
            ->latest('created_at')
            ->forPage($page, 30)
            ->get();

        return $this->renderTwig('@music/admin/tracks.html.twig', [
            'tracks' => $tracks,
            'status' => $status,
            'meta'   => ['title' => 'Manage Tracks — Music Admin'],
        ]);
    }

    /** POST /admin/music/tracks/{id}/remove */
    public function removeTrack(Request $request, string $route_name, array $options): Response
    {
        $id = (int) $request->query->get('id');
        $track = $this->tracks->find($id);
        if ($track) {
            $this->tracks->remove($id);
            $this->artists->adjustTracksCount((int) $track['artist_id'], -1);
            if ($track['album_id']) {
                $this->albums->adjustTracksCount((int) $track['album_id'], -1);
            }
        }
        return $this->redirect('/admin/music/tracks');
    }

    /** GET /admin/music/artists */
    public function artists(Request $request, string $route_name, array $options): Response
    {
        $artists = $this->database->table('music_artists')->latest('created_at')->limit(50)->get();
        foreach ($artists as &$artist) {
            $artist['_avatar'] = $this->mediaUrl->url($artist['avatar_url'] ?? null);
        }
        unset($artist);

        return $this->renderTwig('@music/admin/artists.html.twig', [
            'artists' => $artists,
            'meta'    => ['title' => 'Manage Artists — Music Admin'],
        ]);
    }

    /** POST /admin/music/artists/{id}/suspend */
    public function suspendArtist(Request $request, string $route_name, array $options): Response
    {
        $this->artists->suspend((int) $request->query->get('id'));
        return $this->redirect('/admin/music/artists');
    }

    /** POST /admin/music/artists/{id}/reinstate */
    public function reinstateArtist(Request $request, string $route_name, array $options): Response
    {
        $this->artists->reinstate((int) $request->query->get('id'));
        return $this->redirect('/admin/music/artists');
    }

    /** GET /admin/music/reports */
    public function reports(Request $request, string $route_name, array $options): Response
    {
        $status = (string) $request->query->get('status', 'pending');

        $reports = $this->database->table('music_reports')
            ->where('status', '=', $status)
            ->latest('created_at')
            ->limit(50)
            ->get();

        return $this->renderTwig('@music/admin/reports.html.twig', [
            'reports' => $reports,
            'status'  => $status,
            'meta'    => ['title' => 'Reports — Music Admin'],
        ]);
    }

    /** POST /admin/music/reports/{id}/resolve */
    public function resolveReport(Request $request, string $route_name, array $options): Response
    {
        $this->database->table('music_reports')
            ->where('id', '=', (int) $request->query->get('id'))
            ->update(['status' => 'resolved']);

        return $this->redirect('/admin/music/reports');
    }
}
