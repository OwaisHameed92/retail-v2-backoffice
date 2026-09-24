<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Contracts\Session\Session;

/**
 * The branch chosen in the tenant top-bar switcher, or null for "All branches". A stale choice (another
 * company's branch, an inactive or deleted branch) is dropped. Later modules filter their data by this.
 *
 *     $branch = app(ResolveCurrentBranch::class)->handle($request->session());
 *     $sales->when($branch, fn ($q) => $q->where('branch_id', $branch->id));
 */
class ResolveCurrentBranch
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Session $session): ?Branch
    {
        $branchId = $session->get(SwitchCurrentBranch::SESSION_KEY);

        if (! is_string($branchId) || ! $this->currentCompany->has()) {
            return null;
        }

        $branch = Branch::query()->active()->find($branchId);

        if ($branch === null) {
            $session->forget(SwitchCurrentBranch::SESSION_KEY);
        }

        return $branch;
    }
}
