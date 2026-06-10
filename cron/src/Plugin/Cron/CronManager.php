<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Cron;

use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionInterface;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionSubscriberInterface;
use Simp\Pindrop\Plugin\PluginManager;

class CronManager
{
    protected array $cron        = [];
    protected array $subscribers = [];

    public function __construct(PluginManager $pluginManager)
    {
        // Load cron job definitions from cron.yml across all plugins
        $list = $pluginManager->getPluginsYamlContent('cron');
        foreach ($list as $plugin) {
            foreach ($plugin as $name => $cron) {
                if (!empty($cron['status']) && !empty($cron['class'])) {
                    if (in_array(CronDefinitionInterface::class, class_implements($cron['class']) ?? [])) {
                        $this->cron[$name] = new $cron['class'];
                    }
                }
            }
        }

        // Load subscriber definitions from cron.subscriber.yml across all plugins
        $list = $pluginManager->getPluginsYamlContent('cron.subscriber');
        foreach ($list as $plugin) {
            foreach ($plugin as $name => $class) {
                if (in_array(CronDefinitionSubscriberInterface::class, class_implements($class) ?? [])) {
                    $this->subscribers[$name] = new $class(\getAppContainer()->get('database'));
                }
            }
        }
    }

    public function getDefinition(string $name): ?CronDefinitionInterface
    {
        return $this->cron[$name] ?? null;
    }

    public function getDefinitions(): array
    {
        return $this->cron;
    }

    public function getJobs(): array
    {
        return \getAppContainer()->get('database')
            ->table('schedulers')
            ->get();
    }

    public function getJob(int $id): ?array
    {
        return \getAppContainer()->get('database')
            ->table('schedulers')
            ->where('id', '=', $id)
            ->first();
    }

    /** @return array<CronDefinitionSubscriberInterface> */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }

    public function getSubscriber(string $subscriberId): ?CronDefinitionSubscriberInterface
    {
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber->id() === $subscriberId) {
                return $subscriber;
            }
        }
        return null;
    }
}
