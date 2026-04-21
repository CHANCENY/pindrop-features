<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Cron;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionInterface;
use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionSubscriberInterface;
use Simp\Pindrop\Plugin\PluginManager;

class CronManager
{
    protected array $cron = [];
    protected array $subscribers = [];

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function __construct(PluginManager $pluginManager)
    {
        $list = $pluginManager->getPluginsYamlContent("cron");
        foreach ($list as $plugin) {
            foreach ($plugin as $name => $cron) {
                if (!empty($cron['status']) && !empty($cron['class'])) {
                    if (in_array(CronDefinitionInterface::class,class_implements($cron['class']) ?? [])) {
                        $this->cron[$name] = new $cron['class'];
                    }
                }
            }
        }

        $list = $pluginManager->getPluginsYamlContent("cron.subscriber");
        foreach ($list as $plugin) {
            foreach ($plugin as $name => $cron) {
                if (in_array(CronDefinitionSubscriberInterface::class,class_implements($cron))) {
                    $this->subscribers[$name] = new $cron(\getAppContainer()->get('database'));
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
        $sql = "SELECT * FROM `schedulers`";
        return \getAppContainer()->get('database')->query($sql)->fetchAll();
    }

    public function getJob(int $id)
    {
        $sql = "SELECT * FROM `schedulers` WHERE `id` = :id";
        return \getAppContainer()->get('database')->query($sql,  $id)->fetch();
    }

    /**
     * @return array<CronDefinitionSubscriberInterface>
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }

    /**
     * @param string $subscriberId
     * @return CronDefinitionSubscriberInterface|null
     */
    public function getSubscriber(string $subscriberId): ?CronDefinitionSubscriberInterface
    {
        $subscriberObject = null;
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber->id() === $subscriberId) {
                $subscriberObject = $subscriber;
                break;
            }
        }
        return $subscriberObject;
    }

}