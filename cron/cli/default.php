<?php


use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\CronManager;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\SchedulerManager;

return [
    'cron:run' => "runScheduler",
];

/**
 * @throws DependencyException
 * @throws NotFoundException
 * @throws DatabaseException
 */
function runScheduler(CLIPrinter $printer, ...$values): void
{
    $printer->printLine("Running Scheduler", GREEN);

    /**@var CronManager $cronManager**/
    $cronManager = \getAppContainer()->get('cron.manager');

    /**@var SchedulerManager $scheduleManager**/
    $scheduleManager = \getAppContainer()->get('cron.scheduler');

    $backgroundScript = __DIR__ . "/bacground.php";
    if (file_exists($backgroundScript)) {
        if (!is_executable($backgroundScript)) {
            chmod($backgroundScript, 0777);
        }
    }

    $subscribers = $cronManager->getSubscribers();
    foreach ($subscribers as $subscriber) {
        $serialized = base64_encode(serialize($subscriber->id()));
        $command = 'php '.$backgroundScript.' "' . $serialized . '" > /dev/null 2>&1 &';

        if (isFunctionEnabled('exec')) {
            exec($command);
            $printer->printLine("Schedules dispatched!", GREEN);
        }
        else {
            $printer->printLine("exec php function is disabled.");
        }
    }

}

function isFunctionEnabled(string $function): bool
{
    $disabled = explode(',', ini_get('disable_functions'));
    $disabled = array_map('trim', $disabled);

    return function_exists($function) && !in_array($function, $disabled);
}