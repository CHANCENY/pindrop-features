<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\music\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\music\src\Services\ListeningHistoryService;
use Simp\Pindrop\Modules\music\src\Services\TrackService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PlaybackController
 *
 * Gated behind can_music_play_track (i.e. requires login) rather than
 * open to anonymous requests. This is a deliberate simplification: whether
 * an anonymous (not-logged-in) visitor's request resolves to a real
 * bypass of DatabasePermissionGuard or is checked against the 'anonymous'
 * role's grants (which only include db.music.read, not write) wasn't
 * something worth gambling on for a write path — same reasoning qa's
 * vote/bookmark/comment routes used (all require login). Known
 * consequence: plays_count / trending only reflect logged-in listening,
 * not anonymous streams. Revisit if/when that ambiguity gets resolved
 * with certainty; it's a one-line routing.yml change either way.
 */
class PlaybackController extends ControllerBase
{
    public function __construct(
        protected TrackService $tracks,
        protected ListeningHistoryService $history,
        protected CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('music.track'),
            $container->get('music.history'),
            $container->get('current_user'),
        );
    }

    /** POST /music/track/{id}/play-beacon */
    public function recordPlay(Request $request, string $route_name, array $options): Response
    {
        $trackId = (int) $request->query->get('id');
        $track = $this->tracks->find($trackId);

        if (!$track) {
            return $this->json(['success' => false, 'error' => 'Track not found'], 404);
        }

        $this->tracks->incrementPlaysCount($trackId);

        $userId = $this->currentUser->getUserId();
        if ($userId) {
            $this->history->record((int) $userId, $trackId);
        }

        return $this->json(['success' => true]);
    }
}
