<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Branch;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A branch was added (AddBranch), after its tills. Dispatched inside the action's transaction: module 1.13 updates the Direct Debit amount
 * (per-branch pricing) after commit.
 */
final class BranchAdded
{
    use Dispatchable;

    public function __construct(public readonly Branch $branch) {}
}
