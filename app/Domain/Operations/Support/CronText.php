<?php

namespace App\Domain\Operations\Support;

/**
 * Plain words for the cron expressions routes/console.php uses ("Every 5 minutes", "Daily at 06:00"). Anything
 * unusual falls back to the expression itself.
 */
final class CronText
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public static function describe(string $expression): string
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($parts) !== 5) {
            return $expression;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        if ($day !== '*' || $month !== '*') {
            return $expression;
        }

        if ($hour === '*' && $weekday === '*') {
            return match (true) {
                $minute === '*' => 'Every minute',
                (bool) preg_match('/^\*\/(\d+)$/', $minute, $m) => "Every {$m[1]} minutes",
                (bool) preg_match('/^(\d+)-59\/(\d+)$/', $minute, $m) => "Every {$m[2]} minutes from :".sprintf('%02d', (int) $m[1]),
                ctype_digit($minute) => $minute === '0' ? 'Hourly' : 'Hourly at :'.sprintf('%02d', (int) $minute),
                default => $expression,
            };
        }

        if (! ctype_digit($minute) || ! ctype_digit($hour)) {
            return $expression;
        }

        $time = sprintf('%02d:%02d', (int) $hour, (int) $minute);

        if ($weekday === '*') {
            return "Daily at {$time}";
        }

        return ctype_digit($weekday) && (int) $weekday <= 7 ? 'Every '.self::DAYS[(int) $weekday]." at {$time}" : $expression;
    }
}
