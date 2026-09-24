<?php

namespace App\Domain\Shared\Casts;

use App\Domain\Shared\Support\ApiDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a UTC datetime and serialises it as ISO-8601 with `Z` (`2026-09-23T09:41:12Z`).
 * A value with no offset is treated as UTC, as the till contract says.
 *
 * Usage: `'completed_at' => UtcDateTimeCast::class`.
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
final class UtcDateTimeCast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return ApiDate::parse($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return ApiDate::parse($value)?->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return ApiDate::format($value);
    }
}
