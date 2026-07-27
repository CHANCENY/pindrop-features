<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\qa\src\Services\AnswerService;
use Simp\Pindrop\Modules\qa\src\Services\BookmarkService;
use Simp\Pindrop\Modules\qa\src\Services\CommentService;
use Simp\Pindrop\Modules\qa\src\Services\QaSignalDispatcher;
use Simp\Pindrop\Modules\qa\src\Services\QuestionService;
use Simp\Pindrop\Modules\qa\src\Services\ReputationService;
use Simp\Pindrop\Modules\qa\src\Services\VoteService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class InteractionController extends ControllerBase
{
    public function __construct(
        protected VoteService $votes,
        protected BookmarkService $bookmarks,
        protected CommentService $comments,
        protected QuestionService $questions,
        protected AnswerService $answers,
        protected ReputationService $reputation,
        protected QaSignalDispatcher $signals,
        protected DatabaseService $database,
        protected ?CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('qa.vote'),
            $container->get('qa.bookmark'),
            $container->get('qa.comment'),
            $container->get('qa.question'),
            $container->get('qa.answer'),
            $container->get('qa.reputation'),
            $container->get('qa.signal'),
            $container->get('database'),
            $container->get('current_user') ?? CurrentUser::resolveAnonymous(),
        );
    }

    /** POST /vote/{type}/{id} — type is 'question'|'answer', $id is the votable's ID. */
    public function vote(Request $request, string $route_name, array $options): Response
    {
        $votableType = (string) $request->query->get('type');
        $votableId = (int) $request->query->get('id');
        $data = $request->request->all();
        unset($data['_csrf_token']);
        $voteType = (string) ($data['vote_type'] ?? '');

        if (!in_array($votableType, ['question', 'answer'], true) || !in_array($voteType, ['upvote', 'downvote'], true)) {
            return $this->json(['success' => false, 'error' => 'Invalid vote request'], 422);
        }

        $votable = $votableType === 'question' ? $this->questions->find($votableId) : $this->answers->find($votableId);
        if (!$votable) {
            return $this->json(['success' => false, 'error' => 'Not found'], 404);
        }

        $voterId = (int) $this->currentUser->getUserId();
        $ownerId = (int) $votable['user_id'];

        if ($voterId === $ownerId) {
            return $this->json(['success' => false, 'error' => 'You cannot vote on your own content'], 403);
        }

        $result = $this->votes->castVote($voterId, $votableType, $votableId, $voteType);

        if ($votableType === 'question') {
            $this->questions->adjustVotesCount($votableId, $result['delta']);
        } else {
            $this->answers->adjustVotesCount($votableId, $result['delta']);
        }

        // Reputation only changes on the net first-time vote / removal, not
        // on every click — award/revoke based on the action reported.
        if ($result['action'] === 'created') {
            $this->reputation->awardForVoteReceived($ownerId, $votableType, $votableId, $voteType);
        } elseif ($result['action'] === 'removed') {
            $this->reputation->revokeVoteReceived($ownerId, $votableType, $votableId, $voteType);
        } elseif ($result['action'] === 'switched') {
            $previousType = $voteType === 'upvote' ? 'downvote' : 'upvote';
            $this->reputation->revokeVoteReceived($ownerId, $votableType, $votableId, $previousType);
            $this->reputation->awardForVoteReceived($ownerId, $votableType, $votableId, $voteType);
        }

        $this->signals->emit('qa.vote.cast', [
            'votable_type' => $votableType,
            'votable_id'   => $votableId,
            'voter_id'     => $voterId,
            'owner_id'     => $ownerId,
            'action'       => $result['action'],
            'vote_type'    => $voteType,
        ]);

        $newTotal = $votableType === 'question'
            ? $this->questions->find($votableId)['votes_count']
            : $this->answers->find($votableId)['votes_count'];

        return $this->json([
            'success' => true,
            'action'  => $result['action'],
            'votes_count' => (int) $newTotal,
        ]);
    }

    /** POST /bookmark/{id} — toggles a bookmark on a question. */
    public function bookmark(Request $request, string $route_name, array $options): Response
    {
        $questionId = (int) $request->query->get('id');
        $question = $this->questions->find($questionId);

        if (!$question) {
            return $this->json(['success' => false, 'error' => 'Question not found'], 404);
        }

        $userId = (int) $this->currentUser->getUserId();
        $nowBookmarked = $this->bookmarks->toggle($userId, $questionId);
        $this->questions->adjustBookmarksCount($questionId, $nowBookmarked ? 1 : -1);

        return $this->json(['success' => true, 'bookmarked' => $nowBookmarked]);
    }

    /** POST /comment/add — body: commentable_type, commentable_id, body, parent_id? */
    public function addComment(Request $request, string $route_name, array $options): Response
    {
        $data = $request->request->all();
        unset($data['_csrf_token']);

        $type = (string) ($data['commentable_type'] ?? '');
        $commentableId = (int) ($data['commentable_id'] ?? 0);
        $body = trim((string) ($data['body'] ?? ''));
        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null;

        if (!in_array($type, ['question', 'answer'], true) || $commentableId <= 0 || mb_strlen($body) < 2) {
            return $this->json(['success' => false, 'error' => 'Invalid comment'], 422);
        }

        $userId = (int) $this->currentUser->getUserId();
        $user = $this->currentUser->getUser();

        $commentId = $this->comments->create(
            $userId,
            $type,
            $commentableId,
            $body,
            $parentId,
            $user?->getDisplayName() ?? ''
        );

        $this->signals->emit('qa.comment.created', [
            'comment_id'       => $commentId,
            'commentable_type' => $type,
            'commentable_id'   => $commentableId,
            'user_id'          => $userId,
        ]);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'comment_id' => $commentId]);
        }

        return $this->redirect($request->headers->get('referer', '/questions'));
    }

    /** POST /report — body: reportable_type, reportable_id, reason */
    public function report(Request $request, string $route_name, array $options): Response
    {
        $data = $request->request->all();
        unset($data['_csrf_token']);

        $type = (string) ($data['reportable_type'] ?? '');
        $id = (int) ($data['reportable_id'] ?? 0);
        $reason = trim((string) ($data['reason'] ?? ''));

        if (!in_array($type, ['question', 'answer', 'comment'], true) || $id <= 0 || $reason === '') {
            return $this->json(['success' => false, 'error' => 'Invalid report'], 422);
        }

        $reporterId = (int) $this->currentUser->getUserId();

        $reportId = $this->database->table('qa_reports')->insert([
            'reporter_id'      => $reporterId,
            'reportable_type'  => $type,
            'reportable_id'    => $id,
            'reason'           => $reason,
        ]);

        $this->signals->emit('qa.report.created', [
            'report_id'       => $reportId,
            'reportable_type' => $type,
            'reportable_id'   => $id,
        ]);

        return $this->json(['success' => true]);
    }
}
