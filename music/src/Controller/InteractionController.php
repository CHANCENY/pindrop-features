<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\music\src\Services\ArtistService;
use Simp\Pindrop\Modules\music\src\Services\FollowService;
use Simp\Pindrop\Modules\music\src\Services\LikeService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class InteractionController extends ControllerBase
{
    public function __construct(
        protected LikeService $likes,
        protected FollowService $follows,
        protected ArtistService $artists,
        protected DatabaseService $database,
        protected CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.like'),
            $container->get('music.follow'),
            $container->get('music.artist'),
            $container->get('database'),
            $container->get('current_user'),
        );
    }

    /** POST /music/like/{type}/{id} */
    public function toggleLike(Request $request, string $route_name, array $options): Response
    {
        $type = (string) $request->query->get('type');
        $id = (int) $request->query->get('id');
        $userId = (int) $this->currentUser->getUserId();

        try {
            $liked = $this->likes->toggle($userId, $type, $id);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json([
            'success'    => true,
            'liked'      => $liked,
            'like_count' => $this->likes->countFor($type, $id),
        ]);
    }

    /** POST /music/follow/{id} — id is the artist_id. */
    public function toggleFollow(Request $request, string $route_name, array $options): Response
    {
        $artistId = (int) $request->query->get('id');
        $artist = $this->artists->find($artistId);

        if (!$artist) {
            return $this->json(['success' => false, 'error' => 'Artist not found'], 404);
        }

        $userId = (int) $this->currentUser->getUserId();
        $following = $this->follows->toggle($userId, $artistId);
        $this->artists->adjustFollowerCount($artistId, $following ? 1 : -1);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'following' => $following]);
        }

        return $this->redirect('/music/artist/' . $artist['slug']);
    }

    /** POST /music/report */
    public function report(Request $request, string $route_name, array $options): Response
    {
        $data = $request->request->all();
        unset($data['_csrf_token']);

        $type = (string) ($data['reportable_type'] ?? '');
        $id = (int) ($data['reportable_id'] ?? 0);
        $reason = trim((string) ($data['reason'] ?? ''));

        if (!in_array($type, ['track', 'album', 'artist', 'playlist'], true) || $id <= 0 || $reason === '') {
            return $this->json(['success' => false, 'error' => 'Invalid report'], 422);
        }

        $reporterId = (int) $this->currentUser->getUserId();

        $this->database->table('music_reports')->insert([
            'reporter_id'     => $reporterId,
            'reportable_type' => $type,
            'reportable_id'   => $id,
            'reason'          => $reason,
        ]);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true]);
        }

        return $this->redirect($request->headers->get('referer', '/music'));
    }
}
