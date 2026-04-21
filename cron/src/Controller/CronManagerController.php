<?php

namespace Simp\Pindrop\Modules\cron\src\Controller;

use Cron\CronExpression;
use DateTimeZone;
use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\CronManager;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\SchedulerManager;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CronManagerController extends ControllerBase
{


    public function __construct(protected CronManager $cronManager, protected SchedulerManager $schedulerManager)
    {
        parent::__construct();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function create(ContainerInterface $container): CronManagerController
    {
        return new static(
            $container->get('cron.manager'),
            $container->get('cron.scheduler'),
        );
    }

    public function dashboard(Request $request, string $route_name, array $options): Response
    {
        $schedules = $this->schedulerManager->getSchedules();
        return $this->renderTwig("@cron/dashboard.html.twig", [
            "schedules" => $schedules,
        ]);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException|Exception
     */
    public function createJob(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            $definition = $request->request->all();
            unset($definition['_csrf_token']);
            if ($this->schedulerManager->create($definition)) {
                Message::info("Create job success");
                return $this->redirect(Url::routeByName('cron.dashboard'));
            }

        }
        return $this->renderTwig("@cron/create_job.html.twig", [
            'definitions' => $this->cronManager->getDefinitions(),
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function editSchedule(Request $request, string $route_name, array $options): Response
    {
        $schedule = $this->schedulerManager->getSchedule($request->query->get('id'));
        if ($request->isMethod('POST')) {
            $status = $request->request->get('status');
            if ($this->schedulerManager->updateSchedule(['status' => $status], $request->query->get('id'))) {
                Message::info("Schedule '{$schedule->job_name}' status updated");
                return $this->redirect(Url::routeByName('cron.dashboard'));
            }
        }

        return $this->renderTwig("@cron/edit_schedule.html.twig", [
            'status' => $this->schedulerManager->getSchedule($request->query->get('id'))?->status,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function deleteSchedule(Request $request, string $route_name, array $options): Response
    {
        $schedule = $this->schedulerManager->getSchedule($request->query->get('id'));
        if ($request->getMethod() === 'POST') {
            if ($this->schedulerManager->deleteSchedule($schedule->id)) {
                Message::info("Schedule '{$schedule->job_name}' deleted");
                return $this->redirect(Url::routeByName('cron.dashboard'));
            }
        }
        return $this->renderTwig("@cron/delete_schedule.html.twig",['schedule' => $schedule]);
    }

    /**
     * @throws Exception
     */
    public function createSchedule(Request $request, string $route_name, array $options): Response
    {
        $jobs = $this->cronManager->getJobs();
        $subscribers = $this->cronManager->getSubscribers();
        if (empty($jobs)) {
            Message::info("No jobs created yet!");
            return $this->redirect(Url::routeByName('cron.dashboard'));
        }

        if ($request->isMethod('POST')) {
            $definition = $request->request->all();

            if (!empty($definition['expression']) && !empty($definition['job'])) {
                unset($definition['_csrf_token']);
                $definition['status'] = 'running';

                $job = $this->cronManager->getJob($definition['job']);

                $timezone = $job['timezone'] ?? "Africa/Blantyre";

                $definition['next_run'] = new CronExpression($definition['expression'])->getNextRunDate(timeZone: $timezone)->
                format('Y-m-d H:i:s');

                if ($this->schedulerManager->addSchedule($definition)) {
                    Message::info("Create schedule success");
                    return $this->redirect(Url::routeByName('cron.dashboard'));
                }
            }
        }
        return $this->renderTwig("@cron/create_schedule.html.twig", [
            'jobs' => $jobs,
            'subscribers' => $subscribers,
        ]);
    }

    public function runSchedule(Request $request, string $route_name, array $options): Response
    {
        $schedule = $this->schedulerManager->getSchedule($request->query->get('id'));

        if ($request->isMethod('POST')) {

            $backgroundScript = __DIR__ . "/../../cli/manual.php";
            if (file_exists($backgroundScript)) {
                if (!is_executable($backgroundScript)) {
                    chmod($backgroundScript, 0777);
                }
            }

            $serialized = base64_encode(serialize($schedule->id));
            $command = 'php '.$backgroundScript.' "' . $serialized . '" > /dev/null 2>&1 &';

            if ($this->isFunctionEnabled('exec')) {
                exec($command);
                Message::info("Schedule '{$schedule->job_name}' running");
            }
            else {
                Message::error("exec php function is disabled.");
            }

            return $this->redirect(Url::routeByName('cron.dashboard'));
        }

        return $this->renderTwig("@cron/manually_run.html.twig", [
            'schedule' => $schedule,
        ]);
    }

    private function isFunctionEnabled(string $function): bool
    {
        $disabled = explode(',', ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        return function_exists($function) && !in_array($function, $disabled);
    }

    public function logSchedule(Request $request, string $route_name, array $options): Response
    {
        $schedule = $this->schedulerManager->getSchedule($request->query->get('id'));
        $logs = $schedule->getLogs();
        return $this->renderTwig("@cron/logs.html.twig", [
            'schedule' => $schedule,
            'logs'     => $logs
        ]);
    }
}