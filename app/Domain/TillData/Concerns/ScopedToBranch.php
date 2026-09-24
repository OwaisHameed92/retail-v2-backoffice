<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Till row with a `branch_id` (own, from its parent, or the sending branch).
 *
 *     Sale::query()->forBranch($branch)->get();   // null = all branches (ResolveCurrentBranch's convention)
 *
 * @mixin Model
 */
trait ScopedToBranch
{
    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<Model>  $query
     */
    public function scopeForBranch(Builder $query, Branch|string|null $branch): void
    {
        if ($branch !== null) {
            $query->where($this->qualifyColumn('branch_id'), $branch instanceof Branch ? $branch->getKey() : $branch);
        }
    }
}
