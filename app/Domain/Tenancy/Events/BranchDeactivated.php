<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Branch;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A branch was closed (DeactivateBranch). Dispatched inside the action's transaction: module 1.13 updates the Direct Debit amount
 * (per-branch pricing) after commit.
 */
final class BranchDeactivated
{
    use Dispatchable;

    public function __construct(public readonly Branch $branch) {}
}
