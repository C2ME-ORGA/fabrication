<?php

namespace App\Support;

class DurationFormatter
{
    public static function format(int $seconds, bool $includeSeconds = false): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours} h";
        }

        if ($minutes > 0) {
            $parts[] = "{$minutes} min";
        }

        if ($includeSeconds || $remaining > 0 || $parts === []) {
            $parts[] = "{$remaining} s";
        }

        return implode(' ', $parts);
    }

    public static function formatMinutes(?int $minutes): string
    {
        return self::format(max(0, (int) $minutes) * 60);
    }
}
