<?php

namespace App\Domain\TillData\Sync;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Registry\FieldDefinition;
use InvalidArgumentException;

/**
 * Validates one payload value against its field type (the generated rule set, derived from the entity schemas)
 * and converts it to what the column stores: decimals as fixed-scale strings, booleans as 0/1, date-times as
 * UTC "Y-m-d H:i:s", embedded JSON strings verbatim. Throws InvalidValue with a short reason.
 */
final class Values
{
    private const INTEGER = '/^-?(?:0|[1-9]\d*)$/';

    private const DECIMAL = '/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/';

    private const UTC_Z = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?Z$/';

    private const ISO = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/';

    /** Digits before the point each decimal type holds: decimal(12,2), (14,4), (14,4), (9,4), (16,6). */
    private const INTEGER_DIGITS = ['money' => 10, 'cost' => 10, 'quantity' => 10, 'percent' => 5, 'rate' => 10];

    private const SCALES = ['money' => 2, 'cost' => 4, 'quantity' => 4, 'percent' => 4, 'rate' => 6];

    public static function toColumn(FieldDefinition $field, mixed $value): mixed
    {
        if ($value === null) {
            if (! $field->nullable) {
                throw new InvalidValue('must not be null');
            }

            return null;
        }

        return match ($field->type) {
            'string', 'text', 'enum' => self::string($value, $field->maxLength()),
            'longText', 'secret' => self::string($value, null),
            'int', 'bigint' => self::integer($value, $field->type === 'int'),
            'money', 'cost', 'quantity', 'percent', 'rate' => self::decimal($value, $field->type),
            'bool' => is_bool($value) ? (int) $value : throw new InvalidValue('must be true or false'),
            'date' => self::date($value),
            'time' => self::time($value),
            'datetime' => self::dateTime($value),
            'json' => is_array($value)
                ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)
                : throw new InvalidValue('must be an object or array'),
            default => throw new InvalidValue("has an unknown type {$field->type}"),
        };
    }

    /**
     * ISO-8601 date-time to UTC "Y-m-d H:i:s" (whole seconds). No offset = UTC (contract section 6).
     */
    public static function dateTime(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidValue('must be an ISO-8601 date-time string');
        }

        if (preg_match(self::UTC_Z, $value, $m) === 1) {
            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $m[4] > 23 || $m[5] > 59 || $m[6] > 59) {
                throw new InvalidValue('is not a real date-time');
            }

            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }

        if (preg_match(self::ISO, $value) !== 1) {
            throw new InvalidValue('must be an ISO-8601 date-time');
        }

        try {
            return (string) ApiDate::parse($value)?->format('Y-m-d H:i:s');
        } catch (InvalidArgumentException) {
            throw new InvalidValue('is not a real date-time');
        }
    }

    /**
     * Whether two column values are the same stored value (DB drivers return decimals as float or string,
     * booleans as int, dates with or without time).
     */
    public static function same(?FieldDefinition $field, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return match ($field?->type) {
            'money', 'cost', 'quantity', 'percent', 'rate' => Money::compare($a, $b) === 0,
            'bool', 'int', 'bigint' => (int) $a === (int) $b,
            'date' => substr((string) $a, 0, 10) === substr((string) $b, 0, 10),
            'datetime' => substr((string) $a, 0, 19) === substr((string) $b, 0, 19),
            // MySQL re-formats JSON columns, so compare the decoded documents.
            'json' => json_decode((string) $a, true) == json_decode((string) $b, true),
            default => (string) $a === (string) $b,
        };
    }

    private static function string(mixed $value, ?int $max): string
    {
        if (! is_string($value)) {
            throw new InvalidValue('must be a string');
        }

        if ($max !== null && ($max === 65535 ? strlen($value) : mb_strlen($value)) > $max) {
            throw new InvalidValue("is longer than {$max} characters");
        }

        return $value;
    }

    private static function integer(mixed $value, bool $int32): int
    {
        if (is_string($value) && preg_match(self::INTEGER, $value) === 1 && strlen($value) <= 18) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            throw new InvalidValue('must be a whole number');
        }

        if ($int32 && ($value > 2_147_483_647 || $value < -2_147_483_648)) {
            throw new InvalidValue('is too large');
        }

        return $value;
    }

    /**
     * Plain string work for the common case (at most `scale` decimals); bcmath only when rounding is needed.
     * A push carries ~10 decimals per row, so this is the applier's hottest path.
     */
    private static function decimal(mixed $value, string $type): string
    {
        $scale = self::SCALES[$type];

        $string = match (true) {
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => (string) $value,
            is_string($value) && preg_match(self::DECIMAL, $value) === 1 => $value,
            default => throw new InvalidValue('must be a number'),
        };

        $dot = strpos($string, '.');
        $fraction = $dot === false ? '' : substr($string, $dot + 1);

        if (str_contains($string, 'E') || strlen($fraction) > $scale) {
            $normalised = Money::normalise($value, $scale);

            // Contract §6 (samples/error-reply.422.json): prices and totals have at most 2 dp, costs and quantities 4.
            // Trailing zeros are fine ("1.4500"); a value the column cannot hold exactly is refused, never rounded.
            if (bccomp(Money::parse($value), $normalised, 12) !== 0) {
                throw new InvalidValue("has more than {$scale} decimal places");
            }
        } else {
            $whole = $dot === false ? $string : substr($string, 0, $dot);
            $normalised = $whole.'.'.str_pad($fraction, $scale, '0');

            if ($normalised[0] === '-' && trim($normalised, '-0.') === '') {
                $normalised = substr($normalised, 1);
            }
        }

        $integerDigits = strlen(ltrim(strstr(ltrim($normalised, '-'), '.', true) ?: '0', '0'));

        if ($integerDigits > self::INTEGER_DIGITS[$type]) {
            throw new InvalidValue('is too large');
        }

        return $normalised;
    }

    private static function date(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidValue('must be a date (YYYY-MM-DD)');
        }

        return $value;
    }

    private static function time(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d)(?:\.\d+)?)?$/', $value, $m) !== 1) {
            throw new InvalidValue('must be a time (HH:MM:SS)');
        }

        return $m[1].':'.$m[2].':'.($m[3] ?? '00');
    }
}
