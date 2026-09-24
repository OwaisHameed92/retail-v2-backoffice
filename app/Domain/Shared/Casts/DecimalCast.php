<?php

namespace App\Domain\Shared\Casts;

use App\Domain\Shared\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixed-scale decimal as a normalised string. Accepts numbers or numeric strings, rounds half away from zero,
 * rejects anything else with an InvalidArgumentException. Never returns a float.
 *
 * @implements CastsAttributes<string|null, mixed>
 */
abstract class DecimalCast implements CastsAttributes
{
    abstract protected function scale(): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Money::normalise($value, $this->scale());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Money::normalise($value, $this->scale());
    }
}
