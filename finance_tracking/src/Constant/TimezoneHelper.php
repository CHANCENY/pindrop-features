<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Constant;

use DateTimeImmutable;
use DateTimeZone;

class TimezoneHelper
{
    /**
     * Get a flat array of all valid timezone identifiers.
     * Example: ['Africa/Abidjan', 'Africa/Accra', ...]
     *
     * @return string[]
     */
    public static function all(): array
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::ALL);
    }

    /**
     * Get a key-value array formatted with current UTC offsets.
     * Example: ['Africa/Blantyre' => '(UTC+02:00) Africa/Blantyre']
     *
     * @param bool $sortByOffset Sort by UTC offset instead of alphabetically
     * @return array<string, string>
     */
    public static function formatted(bool $sortByOffset = true): array
    {
        $timezones = self::all();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $list = [];

        foreach ($timezones as $tz) {
            if ($tz === 'UTC') {
                continue;
            }

            $zone = new DateTimeZone($tz);
            $offsetSeconds = $zone->getOffset($now);
            $formattedOffset = self::formatOffset($offsetSeconds);

            $list[] = [
                'identifier' => $tz,
                'label'      => "({$formattedOffset}) " . str_replace('_', ' ', $tz),
                'offset'     => $offsetSeconds,
            ];
        }

        if ($sortByOffset) {
            usort($list, fn(array $a, array $b) => $a['offset'] <=> $b['offset'] ?: strcmp($a['identifier'], $b['identifier']));
        }

        $result = ['UTC' => '(UTC+00:00) UTC'];
        foreach ($list as $item) {
            $result[$item['identifier']] = $item['label'];
        }

        return $result;
    }

    /**
     * Get timezones grouped by region/continent (ideal for HTML <optgroup>).
     * Example: ['Africa' => ['Africa/Blantyre' => '(UTC+02:00) Blantyre', ...]]
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedByRegion(): array
    {
        $grouped = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach (self::all() as $tz) {
            $parts = explode('/', $tz, 2);
            $region = $parts[0];
            $city = isset($parts[1]) ? str_replace('_', ' ', $parts[1]) : $region;

            $zone = new DateTimeZone($tz);
            $offset = self::formatOffset($zone->getOffset($now));

            $grouped[$region][$tz] = "({$offset}) {$city}";
        }

        return $grouped;
    }

    /**
     * Check if a timezone string is valid.
     */
    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, self::all(), true);
    }

    /**
     * Convert offset in seconds to a readable UTC string (e.g., UTC+02:00).
     */
    private static function formatOffset(int $seconds): string
    {
        $sign = $seconds >= 0 ? '+' : '-';
        $absSeconds = abs($seconds);
        $hours = intdiv($absSeconds, 3600);
        $minutes = intdiv($absSeconds % 3600, 60);

        return sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);
    }
}
