<?php

namespace App\Domain\Shared\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * API date-times: always ISO-8601 UTC with `Z`, whole seconds (`2026-09-23T09:41:12Z`).
 *
 *     ApiDate::format($licence->expires_at);   // "2026-10-01T00:00:00Z" or null
 *     ApiDate::parse('2026-09-23T10:41:12+01:00'); // CarbonImmutable 09:41:12 UTC
 */
final class ApiDate
{
    public const FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function format(mixed $value): ?string
    {
        return self::parse($value)?->format(self::FORMAT);
    }

    public static function now(): string
    {
        return CarbonImmutable::now('UTC')->format(self::FORMAT);
    }

    /**
     * Parse a DateTime, timestamp or string into UTC. Strings with no offset are read as UTC.
     *
     * @throws InvalidArgumentException when the string is not a date.
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (is_int($value)) {
            return CarbonImmutable::createFromTimestamp($value, 'UTC');
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The date must be a string or a date.');
        }

        try {
            return CarbonImmutable::parse($value, new DateTimeZone('UTC'))->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException("The date \"{$value}\" could not be read.");
        }
    }
}
