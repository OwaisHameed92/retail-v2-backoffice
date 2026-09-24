<?php

namespace App\Domain\TillData\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A till enum column. Reads the backed enum, or null when the stored value is empty or not (yet) a case: the sync
 * applier stores enum values it does not know as sent (a newer till may add a value before we regenerate), so a
 * report never crashes on them. The raw value stays in the database: `$model->getRawOriginal($key)`.
 *
 * Usage (generated): `'status' => TillEnumCast::class.':'.SaleStatus::class`.
 *
 * @implements CastsAttributes<BackedEnum|null, mixed>
 */
final class TillEnumCast implements CastsAttributes
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public function __construct(private readonly string $enum) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BackedEnum
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($this->enum)::tryFrom(is_int($value) ? $value : (string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            default => (string) $value,
        };
    }
}
