<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\CronDefinition;

use Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface\CronDefinitionInterface;

class DefaultCronDefinition implements CronDefinitionInterface
{

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return "default";
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return "System Scheduler";
    }

    /**
     * @inheritDoc
     */
    public function getSubscribers(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return "System Scheduler, for defaults cron, signals and slots. this cron should always be enabled.";
    }
}