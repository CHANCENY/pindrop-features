<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\qa\src\Services\AnswerService;
use Simp\Pindrop\Modules\qa\src\Services\BookmarkService;
use Simp\Pindrop\Modules\qa\src\Services\CommentService;
use Simp\Pindrop\Modules\qa\src\Services\QaSignalDispatcher;
use Simp\Pindrop\Modules\qa\src\Services\QuestionService;
use Simp\Pindrop\Modules\qa\src\Services\ReputationService;
use Simp\Pindrop\Modules\qa\src\Services\SeoService;
use Simp\Pindrop\Modules\qa\src\Services\TagService;
use Simp\Pindrop\Modules\qa\src\Services\VoteService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class QuestionController extends ControllerBase
{
    public function __construct(
        protected QuestionService $questions,
        protected AnswerService $answers,
        protected TagService $tags,
        protected VoteService $votes,
        protected BookmarkService $bookmarks,
        protected ReputationService $reputation,
        protected SeoService $seo,
        protected QaSignalDispatcher $signals,
        protected CommentService $comments,
        protected ?CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('qa.question'),
            $container->get('qa.answer'),
            $container->get('qa.tag'),
            $container->get('qa.vote'),
            $container->get('qa.bookmark'),
            $container->get('qa.reputation'),
            $container->get('qa.seo'),
            $container->get('qa.signal'),
            $container->get('qa.comment'),
            $container->get('current_user')  ?? CurrentUser::resolveAnonymous(),
        );
    }

    /** GET /questions — home / question list with filters + sidebar widgets. */
    public function index(Request $request, string $route_name, array $options): Response
    {
        $order = $request->query->get('order', 'newest');
        $page = max(1, (int) $request->query->get('page', 1));

        $list = $this->questions->listQuestions(['order' => $order], $page, 20);
        $total = $this->questions->countQuestions();

        return $this->renderTwig('@qa/question_list.html.twig', [
            'questions'    => $list,
            'order'        => $order,
            'page'         => $page,
            'total'        => $total,
            'per_page'     => 20,
            'trending'     => $this->questions->trending(5),
            'unanswered'   => $this->questions->unanswered(5),
            'popular_tags' => $this->tags->popular(15),
            'meta' => [
                'title'       => 'Questions — Q&A',
                'description' => 'Browse the latest and trending questions from the community.',
            ],
        ]);
    }

    /** GET /questions/{slug} — full question thread. */
    public function view(Request $request, string $route_name, array $options): Response
    {
        $slug = (string) $request->query->get('slug');
        $question = $this->questions->findBySlug($slug);

        if (!$question) {
            return $this->renderTwig('@qa/404.html.twig', [], 404);
        }

        $this->questions->incrementViews((int) $question['id']);

        $answerOrder = $request->query->get('answer_order', 'votes');
        $answers = $this->answers->forQuestion((int) $question['id'], $answerOrder);

        $userId = $this->currentUser->getUserId();
        $userVotes = [];
        $bookmarked = false;

        if ($userId) {
            $votableIds = array_merge([(int) $question['id']], array_map(static fn ($a) => (int) $a['id'], $answers));
            $userVotes = [
                'question' => $this->votes->userVoteFor($userId, 'question', (int) $question['id']),
                'answer'   => $this->votes->userVotesFor($userId, 'answer', array_map(static fn ($a) => (int) $a['id'], $answers)),
            ];
            $bookmarked = $this->bookmarks->isBookmarked($userId, (int) $question['id']);
        }

        // author_username/author_avatar_url are denormalized onto qa_questions
        // at write time (see QuestionService::create() docblock) — the core
        // `users` table can't be queried here since only admin/super_admin
        // may SELECT from it, and this route is open to every visitor.
        $author = ['username' => $question['author_username'] ?: ('User #' . $question['user_id'])];
        $baseUrl = $request->getSchemeAndHttpHost();

        $answerComments = [];
        foreach ($answers as $a) {
            $answerComments[(int) $a['id']] = $this->comments->forCommentable('answer', (int) $a['id']);
        }

        return $this->renderTwig('@qa/question_view.html.twig', [
            'question'    => $question,
            'answers'     => $answers,
            'answer_order' => $answerOrder,
            'tags'        => $this->tags->tagsForQuestion((int) $question['id']),
            'user_votes'  => $userVotes,
            'bookmarked'  => $bookmarked,
            'can_accept'  => $userId && $userId === (int) $question['user_id'],
            'question_comments' => $this->comments->forCommentable('question', (int) $question['id']),
            'answer_comments'   => $answerComments,
            'seo_meta'    => $this->seo->metaTags($question, $author, $baseUrl),
            'json_ld'     => $this->seo->questionJsonLd($question, $author, $answers, $baseUrl),
            'breadcrumb_json_ld' => $this->seo->breadcrumbJsonLd([
                ['name' => 'Questions', 'path' => '/questions'],
                ['name' => $question['title'], 'path' => '/questions/' . $question['slug']],
            ], $baseUrl),
            'meta' => [
                'title'       => $question['title'] . ' — Q&A',
                'description' => $question['meta_description'] ?: mb_substr(strip_tags($question['body']), 0, 160),
            ],
        ]);
    }

    /** GET/POST /questions/ask */
    public function ask(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $title = trim((string) ($data['title'] ?? ''));
            $body = trim((string) ($data['body'] ?? ''));
            $tagsInput = trim((string) ($data['tags'] ?? ''));

            $errors = $this->validateQuestion($title, $body);

            if (empty($errors)) {
                $userId = (int) $this->currentUser->getUserId();
                $user = $this->currentUser->getUser();
                $questionId = $this->questions->create(
                    $userId,
                    $title,
                    $body,
                    $data['meta_description'] ?? null,
                    $user?->getDisplayName() ?? '',
                    $user?->getAvatarUrl()
                );

                $tagNames = array_filter(array_map('trim', explode(',', $tagsInput)));
                if (!empty($tagNames)) {
                    $this->tags->syncQuestionTags($questionId, $tagNames);
                }

                $this->reputation->awardForAskQuestion($userId, $questionId);
                $this->signals->emit('qa.question.created', [
                    'question_id' => $questionId,
                    'user_id'     => $userId,
                    'title'       => $title,
                ]);

                $question = $this->questions->find($questionId);
                return $this->redirect('/questions/' . $question['slug']);
            }

            return $this->renderTwig('@qa/question_ask.html.twig', [
                'errors' => $errors,
                'old'    => $data,
                'meta'   => ['title' => 'Ask a Question — Q&A'],
            ], 422);
        }

        return $this->renderTwig('@qa/question_ask.html.twig', [
            'errors' => [],
            'old'    => [],
            'meta'   => ['title' => 'Ask a Question — Q&A'],
        ]);
    }

    /** GET/POST /questions/{id}/edit — author or moderator only. */
    public function edit(Request $request, string $route_name, array $options): Response
    {
        $id = (int) $request->query->get('id');
        $question = $this->questions->find($id);

        if (!$question) {
            return $this->renderTwig('@qa/404.html.twig', [], 404);
        }

        $userId = $this->currentUser->getUserId();
        $isOwner = $userId && $userId === (int) $question['user_id'];
        $isModerator = in_array('can_qa_moderate', $this->currentUser->getUser()?->getPermissions() ?? [], true);

        if (!$isOwner && !$isModerator) {
            return $this->renderTwig('@qa/403.html.twig', [], 403);
        }

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $title = trim((string) ($data['title'] ?? ''));
            $body = trim((string) ($data['body'] ?? ''));
            $errors = $this->validateQuestion($title, $body);

            if (empty($errors)) {
                $this->questions->update($id, [
                    'title' => $title,
                    'body'  => $body,
                    'meta_description' => $data['meta_description'] ?? null,
                ]);

                $tagsInput = trim((string) ($data['tags'] ?? ''));
                $tagNames = array_filter(array_map('trim', explode(',', $tagsInput)));
                $this->tags->syncQuestionTags($id, $tagNames);

                $this->signals->emit('qa.question.updated', ['question_id' => $id]);

                $updated = $this->questions->find($id);
                return $this->redirect('/questions/' . $updated['slug']);
            }

            return $this->renderTwig('@qa/question_edit.html.twig', [
                'question' => $question,
                'errors'   => $errors,
                'tag_string' => implode(', ', array_column($this->tags->tagsForQuestion($id), 'name')),
            ], 422);
        }

        return $this->renderTwig('@qa/question_edit.html.twig', [
            'question'   => $question,
            'errors'     => [],
            'tag_string' => implode(', ', array_column($this->tags->tagsForQuestion($id), 'name')),
        ]);
    }

    /** POST /questions/{id}/answer */
    public function answer(Request $request, string $route_name, array $options): Response
    {
        $questionId = (int) $request->query->get('id');
        $question = $this->questions->find($questionId);

        if (!$question) {
            return $this->renderTwig('@qa/404.html.twig', [], 404);
        }

        $data = $request->request->all();
        unset($data['_csrf_token']);
        $body = trim((string) ($data['body'] ?? ''));

        if ($body === '' || mb_strlen($body) < 10) {
            return $this->redirect('/questions/' . $question['slug'] . '?error=answer_too_short');
        }

        $userId = (int) $this->currentUser->getUserId();
        $user = $this->currentUser->getUser();
        $answerId = $this->answers->create(
            $questionId,
            $userId,
            $body,
            $user?->getDisplayName() ?? '',
            $user?->getAvatarUrl()
        );
        $this->questions->incrementAnswersCount($questionId, 1);
        $this->reputation->awardForAnswerQuestion($userId, $answerId);

        $this->signals->emit('qa.answer.created', [
            'answer_id'   => $answerId,
            'question_id' => $questionId,
            'user_id'     => $userId,
        ]);

        return $this->redirect('/questions/' . $question['slug'] . '#answer-' . $answerId);
    }

    /** POST /answers/{id}/accept — question owner only. */
    public function acceptAnswer(Request $request, string $route_name, array $options): Response
    {
        $answerId = (int) $request->query->get('id');
        $answer = $this->answers->find($answerId);

        if (!$answer) {
            return $this->json(['success' => false, 'error' => 'Answer not found'], 404);
        }

        $question = $this->questions->find((int) $answer['question_id']);
        $userId = $this->currentUser->getUserId();

        if (!$question || $userId !== (int) $question['user_id']) {
            return $this->json(['success' => false, 'error' => 'Only the question author can accept an answer'], 403);
        }

        $wasAccepted = (bool) $answer['is_accepted'];

        if ($wasAccepted) {
            $this->answers->unmarkAccepted((int) $question['id']);
            $this->questions->setAcceptedAnswer((int) $question['id'], null);
            $this->reputation->revokeAcceptedAnswer((int) $answer['user_id'], $answerId);
        } else {
            $this->answers->markAccepted($answerId, (int) $question['id']);
            $this->questions->setAcceptedAnswer((int) $question['id'], $answerId);
            $this->reputation->awardForAcceptedAnswer((int) $answer['user_id'], $answerId);
            $this->signals->emit('qa.answer.accepted', [
                'answer_id'      => $answerId,
                'question_id'    => (int) $question['id'],
                'answer_user_id' => (int) $answer['user_id'],
            ]);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'accepted' => !$wasAccepted]);
        }

        return $this->redirect('/questions/' . $question['slug'] . '#answer-' . $answerId);
    }

    private function validateQuestion(string $title, string $body): array
    {
        $errors = [];

        if (mb_strlen($title) < 10) {
            $errors['title'] = 'Title must be at least 10 characters.';
        }
        if (mb_strlen($title) > 255) {
            $errors['title'] = 'Title must be under 255 characters.';
        }
        if (mb_strlen($body) < 20) {
            $errors['body'] = 'Question body must be at least 20 characters.';
        }

        return $errors;
    }
}
