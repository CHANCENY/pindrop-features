<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Cron\interface;

interface CronDefinitionInterface
{
    /**
     * Cron definition "key".
     * @return string
     */
    public function key(): string;

    /**
     * Cron definition "name".
     * @return string
     */
    public function name(): string;

    /**
     * Executors subscribers list.
     * @return array<CronDefinitionSubscriber>
     */
    public function getSubscribers(): array;

    /**
     * @return string
     */
    public function description(): string;
}