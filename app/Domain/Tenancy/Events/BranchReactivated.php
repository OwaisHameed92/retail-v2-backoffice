<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Branch;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A closed branch was opened again (ReactivateBranch). Dispatched inside the action's transaction: module 1.13 updates the Direct Debit amount
 * (per-branch pricing) after commit.
 */
final class BranchReactivated
{
    use Dispatchable;

    public function __construct(public readonly Branch $branch) {}
}
