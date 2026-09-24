<?php

namespace App\Domain\Billing\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A calendar date with no time or time zone (invoice period, issue and due dates, Europe/London days).
 * Stored as "Y-m-d" on SQLite and MySQL alike, so SQL comparisons with "Y-m-d" strings behave the same;
 * read back as a CarbonImmutable at midnight UTC. Pass a Carbon (its own calendar date is used) or "Y-m-d".
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
final class CalendarDateCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10), 'UTC') ?: null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::toDateString($value);
    }

    public static function toDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        throw new InvalidArgumentException('A calendar date must be a Carbon date or a "Y-m-d" string.');
    }
}
