<?php

namespace Simp\Pindrop\Modules\cron\src\Plugin\Twig;

use DateTime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('time_ago', [$this, 'timeAgo']),
            new TwigFilter('date_diff', [$this, 'dateDiff']),
        ];
    }

    public function getFunctions(): array {
        return [
            new TwigFunction('cron_job', [$this, 'cronJob']),
        ];
    }

    /**
     * Calculate difference between two dates in seconds
     */
    public function dateDiff(string $date1, string $date2): string
    {
        try {
            $datetime1 = new DateTime($date1);
            $datetime2 = new DateTime($date2);
            $diff = $datetime1->getTimestamp() - $datetime2->getTimestamp();
            
            // Return absolute value in seconds
            return abs($diff);
        } catch (\Exception $e) {
            return '0';
        }
    }

    /**
     * @throws \DateMalformedStringException|\DateInvalidTimeZoneException
     */
    function timeAgo(?string $datetime, ?string $timezone): string
    {
        if (empty($datetime)) {
            return 'unknown';
        }

        if (empty($timezone)) {
            $timezone = "Africa/Blantyre";
        }

        $time = new DateTime($datetime, new \DateTimeZone($timezone))->getTimestamp();
        $now = time();

        if ($time > $now) {
            return 'in the future';
        }

        $diff = $now - $time;

        $units = [
            31536000 => 'year',
            2592000  => 'month',
            604800   => 'week',
            86400    => 'day',
            3600     => 'hour',
            60       => 'minute',
            1        => 'second',
        ];

        foreach ($units as $seconds => $unit) {
            $value = floor($diff / $seconds);
            if ($value >= 1) {
                return $value . ' ' . $unit . ($value > 1 ? 's' : '') . ' ago';
            }
        }

        return 'just now';
    }

    function cronJob(?int $job) {
        if (empty($job)) {
            return ['timezone'=> 'Africa/Blantyre'];
        }

        return \getAppContainer()->get("cron.manager")->getJob($job);
    }
}