<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Cron;

class Log
{
    public function __construct(public $message, public $message_type, public $job_name, public $schedule_id, public $created_at, public $id)
    {
    }
}