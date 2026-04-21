<?php

use Simp\Pindrop\Modules\cron\src\Plugin\Cron\CronManager;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\Schedule;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\SchedulerManager;
use Simp\Pindrop\Plugin\PluginManager;

$vendor = __DIR__ . "/../../../cli/cli.inc.php";

require_once $vendor;

$printer = new \CLIPrinter();

/**@var PluginManager $pluginManager **/
$pluginManager = \getAppContainer()->get('plugin.manager');

if (!file_exists($vendor)) {
    die("run this script on root directory of your project");
}

try{

    $encoded = $argv[1] ?? null;

    if ($encoded === null || $encoded === '' || $encoded === '0') {
        echo "No data received.\n";
        exit(1);
    }

    $subscriber = unserialize(base64_decode($encoded));

    /**@var CronManager $cronManager**/
    $cronManager = \getAppContainer()->get('cron.manager');

    /**@var SchedulerManager $scheduleManager**/
    $scheduleManager = \getAppContainer()->get('cron.scheduler');

    $schedules = $scheduleManager->getSchedulesBySubscriber($subscriber);
    $verifiedSchedules = array_filter($schedules, function (Schedule $schedule) { return $schedule->isDue(); });
    $subscriberObject = $cronManager->getSubscriber($subscriber);
    $messages = $subscriberObject?->runSchedules($verifiedSchedules);
    $printer->printLine($messages);
}catch (Throwable){

}