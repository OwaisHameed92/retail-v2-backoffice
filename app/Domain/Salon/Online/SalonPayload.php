<?php

namespace App\Domain\Salon\Online;

use App\Domain\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads one JSON object of a `sync/salon/*` body by the salon schemas' rules (W3a, salon/schemas/*.schema.json): the
 * sync's own JSON shape, where integers may come as numbers or digit strings and money as numbers or decimal strings.
 * A member that breaks its schema is `422 request.invalid` with `rejectedKey` = its JSON path (README §4.1). Written by
 * hand rather than with a schema library: a booking page can hold tens of thousands of ranges, read in one pass.
 *
 * Messages never repeat a value (a value could be a client's name or a key).
 */
final class SalonPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private readonly array $data, private readonly string $path) {}

    public static function of(mixed $data, string $path = ''): self
    {
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw self::invalid($path === '' ? '$' : $path, 'must be a JSON object');
        }

        return new self($data, $path);
    }

    public function object(string $key): self
    {
        return self::of($this->required($key), $this->at($key));
    }

    public function nullableObject(string $key): ?self
    {
        $value = $this->required($key, nullable: true);

        return $value === null ? null : self::of($value, $this->at($key));
    }

    /**
     * @return list<self>
     */
    public function objects(string $key, int $maxItems = 10000): array
    {
        $items = [];

        foreach ($this->list($key, $maxItems) as $i => $item) {
            $items[] = self::of($item, $this->at($key)."[{$i}]");
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key, int $maxItems = 1000, int $maxLength = 500): array
    {
        $out = [];

        foreach ($this->list($key, $maxItems) as $i => $item) {
            if (! is_string($item) || mb_strlen($item) > $maxLength) {
                throw self::invalid($this->at($key)."[{$i}]", "must be text of at most {$maxLength} characters");
            }

            $out[] = $item;
        }

        return $out;
    }

    public function string(string $key, int $maxLength = 2000, bool $allowEmpty = true): string
    {
        $value = $this->required($key);

        if (! is_string($value) || mb_strlen($value) > $maxLength || (! $allowEmpty && trim($value) === '')) {
            throw self::invalid($this->at($key), $allowEmpty ? "must be text of at most {$maxLength} characters" : 'must be filled in');
        }

        return $value;
    }

    public function nullableString(string $key, int $maxLength = 2000): ?string
    {
        return $this->required($key, nullable: true) === null ? null : $this->string($key, $maxLength);
    }

    public function bool(string $key): bool
    {
        $value = $this->required($key);

        if (! is_bool($value)) {
            throw self::invalid($this->at($key), 'must be true or false');
        }

        return $value;
    }

    public function int(string $key, int $min = 0, int $max = 1000000): int
    {
        $value = $this->required($key);
        $ok = is_int($value) || (is_string($value) && preg_match('/^-?(?:0|[1-9]\d{0,9})$/', $value) === 1);

        if (! $ok || (int) $value < $min || (int) $value > $max) {
            throw self::invalid($this->at($key), "must be a whole number from {$min} to {$max}");
        }

        return (int) $value;
    }

    public function nullableInt(string $key, int $min = 0, int $max = 1000000): ?int
    {
        return $this->required($key, nullable: true) === null ? null : $this->int($key, $min, $max);
    }

    /** Money as the till sends it (a number or a decimal string, at least 0): returned as "12.50". */
    public function money(string $key): string
    {
        $value = $this->required($key);
        $ok = (is_int($value) || is_float($value)) ? is_finite((float) $value) : (is_string($value) && preg_match('/^(?:0|[1-9]\d{0,8})(?:\.\d{1,4})?$/', $value) === 1);

        if (! $ok || (float) $value < 0 || (float) $value > 99999999) {
            throw self::invalid($this->at($key), 'must be an amount of money');
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }

    public function dateTime(string $key): CarbonImmutable
    {
        $value = $this->required($key);

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,7})?)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw self::invalid($this->at($key), 'must be a UTC date and time such as 2026-11-09T08:00:00Z');
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw self::invalid($this->at($key), 'must be a UTC date and time such as 2026-11-09T08:00:00Z');
        }
    }

    public function nullableDateTime(string $key): ?CarbonImmutable
    {
        return $this->required($key, nullable: true) === null ? null : $this->dateTime($key);
    }

    /** A `yyyy-MM-dd` date, returned as it is. */
    public function date(string $key): string
    {
        $value = $this->required($key);

        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw self::invalid($this->at($key), 'must be a date such as 2026-11-09');
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function at(string $key): string
    {
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }

    public static function invalid(string $path, string $message): ApiException
    {
        return new ApiException('request.invalid', "The till sent a salon call the portal could not read: {$path} {$message}.", 422, null, $path);
    }

    /**
     * @return list<mixed>
     */
    private function list(string $key, int $maxItems): array
    {
        $value = $this->required($key);

        if (! is_array($value) || ! array_is_list($value) || count($value) > $maxItems) {
            throw self::invalid($this->at($key), "must be a list of at most {$maxItems} items");
        }

        return $value;
    }

    private function required(string $key, bool $nullable = false): mixed
    {
        if (! array_key_exists($key, $this->data)) {
            throw self::invalid($this->at($key), 'is missing');
        }

        if ($this->data[$key] === null && ! $nullable) {
            throw self::invalid($this->at($key), 'must not be null');
        }

        return $this->data[$key];
    }
}
