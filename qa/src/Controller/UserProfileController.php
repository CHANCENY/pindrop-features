<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\qa\src\Services\AnswerService;
use Simp\Pindrop\Modules\qa\src\Services\BookmarkService;
use Simp\Pindrop\Modules\qa\src\Services\QuestionService;
use Simp\Pindrop\Modules\qa\src\Services\ReputationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UserProfileController extends ControllerBase
{
    public function __construct(
        protected QuestionService $questions,
        protected AnswerService $answers,
        protected BookmarkService $bookmarks,
        protected ReputationService $reputation,
        protected ?CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('qa.question'),
            $container->get('qa.answer'),
            $container->get('qa.bookmark'),
            $container->get('qa.reputation'),
            $container->get('current_user') ?? CurrentUser::resolveAnonymous(),
        );
    }

    /**
     * GET /users/{id} — public profile.
     *
     * NOTE: the display name/avatar shown here is derived from this user's
     * most recent question or answer (see QuestionService::latestAuthorSnapshot),
     * not a live lookup against the core `users` table — DatabasePermissionGuard
     * restricts SELECT on core tables to admin/super_admin, and this route is
     * public. A user who has never posted will show as "User #{id}" until
     * they do. If you need a richer public profile (full bio/website/location
     * from the `users` table), expose it through the `admin` plugin's own
     * user-profile route instead, which already runs with the right context.
     */
    public function view(Request $request, string $route_name, array $options): Response
    {
        $userId = (int) $request->query->get('id');

        $snapshot = $this->questions->latestAuthorSnapshot($userId) ?? $this->answers->latestAuthorSnapshot($userId);
        $displayName = $snapshot['author_username'] ?? ('User #' . $userId);
        $avatarUrl = $snapshot['author_avatar_url'] ?? null;

        return $this->renderTwig('@qa/user_profile.html.twig', [
            'profile_user_id' => $userId,
            'display_name'    => $displayName,
            'avatar_url'      => $avatarUrl,
            'reputation'      => $this->reputation->totalFor($userId),
            'questions_count' => $this->questions->countQuestions(['user_id' => $userId]),
            'answers_count'   => $this->answers->countForUser($userId),
            'recent_questions' => $this->questions->listQuestions(['user_id' => $userId, 'order' => 'newest'], 1, 10),
            'recent_answers'    => $this->answers->forUser($userId, 1, 10),
            'meta' => ['title' => $displayName . ' — Profile'],
        ]);
    }

    /** GET /dashboard/qa — the logged-in user's own dashboard. */
    public function dashboard(Request $request, string $route_name, array $options): Response
    {
        $userId = (int) $this->currentUser->getUserId();
        $page = max(1, (int) $request->query->get('page', 1));

        return $this->renderTwig('@qa/dashboard.html.twig', [
            'my_questions' => $this->questions->listQuestions(['user_id' => $userId, 'order' => 'newest'], $page, 10),
            'my_answers'   => $this->answers->forUser($userId, $page, 10),
            'bookmarks'    => $this->bookmarks->forUser($userId, $page, 10),
            'reputation'   => $this->reputation->totalFor($userId),
            'rep_history'  => $this->reputation->historyFor($userId, 20),
            'meta'         => ['title' => 'My Dashboard — Q&A'],
        ]);
    }
}
