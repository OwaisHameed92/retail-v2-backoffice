<?php

namespace App\Http\Requests\Admin\Leads;

use App\Domain\Mail\Support\MailFormat;
use Illuminate\Support\Carbon;

/**
 * A follow-up entered as a date and optional time in Europe/London (default 09:00), stored as UTC.
 */
final class FollowUpTime
{
    public const DEFAULT_TIME = '09:00';

    public static function from(mixed $date, mixed $time): ?Carbon
    {
        if (! is_string($date) || $date === '') {
            return null;
        }

        $time = is_string($time) && $time !== '' ? $time : self::DEFAULT_TIME;

        return Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", MailFormat::TIMEZONE)?->utc();
    }
}
