<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\Tenancy\Models\Register;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Till row with a `register_id` (the till it was rung on; sale lines/payments/VAT inherit it from the sale).
 *
 * @mixin Model
 */
trait ScopedToRegister
{
    /**
     * @return BelongsTo<Register, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    /**
     * @param  Builder<Model>  $query
     */
    public function scopeForRegister(Builder $query, Register|string|null $register): void
    {
        if ($register !== null) {
            $query->where($this->qualifyColumn('register_id'), $register instanceof Register ? $register->getKey() : $register);
        }
    }
}
