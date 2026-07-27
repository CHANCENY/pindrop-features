<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\qa\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Modules\qa\src\Services\ImportService;
use Simp\Pindrop\Modules\qa\src\Services\QuestionService;
use Simp\Pindrop\Modules\qa\src\Services\TagService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ModerationController extends ControllerBase
{
    public function __construct(
        protected QuestionService $questions,
        protected TagService $tags,
        protected DatabaseService $database,
        protected ImportService $import,
        protected ?CurrentUser $currentUser
    ) {
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('qa.question'),
            $container->get('qa.tag'),
            $container->get('database'),
            $container->get('qa.import'),
            $container->get('current_user') ?? CurrentUser::resolveAnonymous(),
        );
    }

    /** GET /admin/qa — key metrics overview. */
    public function dashboard(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig('@qa/admin/dashboard.html.twig', [
            'total_questions'  => $this->database->table('qa_questions')->where('status', '!=', 'deleted')->count(),
            'total_answers'    => $this->database->table('qa_answers')->where('status', '=', 'visible')->count(),
            'total_comments'   => $this->database->table('qa_comments')->count(),
            'total_tags'       => $this->database->table('qa_tags')->count(),
            'pending_reports'  => $this->database->table('qa_reports')->where('status', '=', 'pending')->count(),
            'most_viewed'      => $this->questions->listQuestions(['order' => 'views'], 1, 10),
            'meta'             => ['title' => 'Q&A Admin Dashboard'],
        ]);
    }

    /** GET /admin/qa/questions */
    public function questions(Request $request, string $route_name, array $options): Response
    {
        $status = $request->query->get('status');
        $page = max(1, (int) $request->query->get('page', 1));

        return $this->renderTwig('@qa/admin/questions.html.twig', [
            'questions' => $this->questions->listQuestions($status ? ['status' => $status] : [], $page, 30),
            'status'    => $status,
            'meta'      => ['title' => 'Manage Questions — Q&A Admin'],
        ]);
    }

    /** POST /admin/qa/questions/{id}/close */
    public function closeQuestion(Request $request, string $route_name, array $options): Response
    {
        $id = (int) $request->query->get('id');
        $this->questions->close($id);
        return $this->redirect('/admin/qa/questions');
    }

    /** POST /admin/qa/questions/{id}/delete */
    public function deleteQuestion(Request $request, string $route_name, array $options): Response
    {
        $id = (int) $request->query->get('id');
        $this->questions->softDelete($id);
        return $this->redirect('/admin/qa/questions');
    }

    /** GET /admin/qa/reports */
    public function reports(Request $request, string $route_name, array $options): Response
    {
        $status = $request->query->get('status', 'pending');

        $reports = $this->database->table('qa_reports')
            ->where('status', '=', $status)
            ->latest('created_at')
            ->limit(50)
            ->get();

        return $this->renderTwig('@qa/admin/reports.html.twig', [
            'reports' => $reports,
            'status'  => $status,
            'meta'    => ['title' => 'Reports — Q&A Admin'],
        ]);
    }

    /** POST /admin/qa/reports/{id}/resolve */
    public function resolveReport(Request $request, string $route_name, array $options): Response
    {
        $id = (int) $request->query->get('id');
        $this->database->table('qa_reports')->where('id', '=', $id)->update(['status' => 'resolved']);
        return $this->redirect('/admin/qa/reports');
    }

    /** GET/POST /admin/qa/tags — list tags, edit descriptions. */
    public function tags(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            unset($data['_csrf_token']);

            $tagId = (int) ($data['tag_id'] ?? 0);
            if ($tagId > 0) {
                $this->database->table('qa_tags')->where('id', '=', $tagId)->update([
                    'description' => trim((string) ($data['description'] ?? '')),
                ]);
            }
            return $this->redirect('/admin/qa/tags');
        }

        return $this->renderTwig('@qa/admin/tags.html.twig', [
            'tags' => $this->tags->all(),
            'meta' => ['title' => 'Manage Tags — Q&A Admin'],
        ]);
    }

    /**
     * GET/POST /admin/qa/import
     *
     * Accepts either an uploaded .json file (field name `import_file`) or
     * raw JSON pasted into a textarea (field name `import_json`) — same
     * shape as questions_data_with_answers.json: a JSON array of
     * {title, body, tags, answers[]} objects. Shares ImportService with the
     * `qa:import` CLI command, so behavior is identical either way.
     */
    public function import(Request $request, string $route_name, array $options): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->renderTwig('@qa/admin/import.html.twig', [
                'result' => null,
                'error'  => null,
                'meta'   => ['title' => 'Import Questions — Q&A Admin'],
            ]);
        }

        $user = $this->currentUser->getUser();
        $importedByUserId = (int) ($this->currentUser->getUserId() ?? 0);
        $importedByUsername = $user?->getDisplayName() ?? 'Import Bot';

        $json = null;
        $uploaded = $request->files->get('import_file');

        if ($uploaded && $uploaded->isValid()) {
            $json = file_get_contents($uploaded->getPathname()) ?: null;
        } elseif (!empty($request->request->get('import_json'))) {
            $json = (string) $request->request->get('import_json');
        }

        if ($json === null || trim($json) === '') {
            return $this->renderTwig('@qa/admin/import.html.twig', [
                'result' => null,
                'error'  => 'Please choose a .json file or paste JSON to import.',
                'meta'   => ['title' => 'Import Questions — Q&A Admin'],
            ], 422);
        }

        try {
            $result = $this->import->importFromJson($json, $importedByUserId, $importedByUsername);
        } catch (\JsonException $e) {
            return $this->renderTwig('@qa/admin/import.html.twig', [
                'result' => null,
                'error'  => 'Invalid JSON: ' . $e->getMessage(),
                'meta'   => ['title' => 'Import Questions — Q&A Admin'],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->renderTwig('@qa/admin/import.html.twig', [
                'result' => null,
                'error'  => $e->getMessage(),
                'meta'   => ['title' => 'Import Questions — Q&A Admin'],
            ], 422);
        }

        return $this->renderTwig('@qa/admin/import.html.twig', [
            'result' => $result,
            'error'  => null,
            'meta'   => ['title' => 'Import Questions — Q&A Admin'],
        ]);
    }
}
