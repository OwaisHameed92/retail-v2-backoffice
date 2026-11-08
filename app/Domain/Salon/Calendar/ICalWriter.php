<?php

namespace App\Domain\Salon\Calendar;

use Carbon\CarbonImmutable;

/**
 * Writes an iCalendar (RFC 5545) document: CRLF line ends, TEXT values escaped (backslash, semicolon, comma, new
 * lines), and content lines folded at 75 octets without splitting a UTF-8 character (continuation lines start with
 * one space). Times are UTC with `Z`, so no VTIMEZONE is needed; `X-WR-TIMEZONE` tells calendar apps the shop's zone.
 */
final class ICalWriter
{
    private const MAX_OCTETS = 75;

    /**
     * @param  list<CalendarEvent>  $events
     */
    public static function render(string $name, string $timezone, array $events, CarbonImmutable $now): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Switch & Save//SSPOS Salon calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text($name),
            'X-WR-TIMEZONE:'.$timezone,
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
            'X-PUBLISHED-TTL:PT15M',
        ];

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$event->uid;
            $lines[] = 'DTSTAMP:'.self::time($now);
            $lines[] = 'DTSTART:'.self::time($event->startsAt);
            $lines[] = 'DTEND:'.self::time($event->endsAt);
            $lines[] = 'SUMMARY:'.self::text($event->summary);

            if ($event->description !== null) {
                $lines[] = 'DESCRIPTION:'.self::text($event->description);
            }

            if ($event->location !== null) {
                $lines[] = 'LOCATION:'.self::text($event->location);
            }

            $lines[] = 'STATUS:'.($event->cancelled ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'TRANSP:'.($event->cancelled ? 'TRANSPARENT' : 'OPAQUE');

            if ($event->lastModified !== null) {
                $lines[] = 'LAST-MODIFIED:'.self::time($event->lastModified);
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** A TEXT value (RFC 5545 §3.3.11): `\` `;` `,` escaped, any new line as `\n`, other control characters dropped. */
    public static function text(string $value): string
    {
        $value = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $value);
        $value = (string) preg_replace('/\r\n|\r|\n/', '\\n', $value);

        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value);
    }

    /** A UTC DATE-TIME: `20261108T093000Z`. */
    public static function time(CarbonImmutable $at): string
    {
        return $at->utc()->format('Ymd\THis\Z');
    }

    /** Folds a content line at 75 octets (RFC 5545 §3.1), never inside a UTF-8 character. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_OCTETS) {
            return $line;
        }

        $parts = [];
        $current = '';
        $limit = self::MAX_OCTETS;

        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $parts[] = $current;
                $current = '';
                $limit = self::MAX_OCTETS - 1; // the leading space of a continuation line is one of its 75 octets
            }

            $current .= $char;
        }

        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
