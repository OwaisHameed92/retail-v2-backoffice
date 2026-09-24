<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

/**
 * Sets the branch the tenant portal is filtered to (the top-bar switcher). Null means "All branches".
 * Only active branches of the current company are accepted (the lookup runs under the company scope).
 */
class SwitchCurrentBranch
{
    public const SESSION_KEY = 'current_branch_id';

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @throws ValidationException
     */
    public function handle(?string $branchId, Session $session): ?Branch
    {
        $this->currentCompany->require();

        if ($branchId === null || $branchId === '') {
            $session->forget(self::SESSION_KEY);

            return null;
        }

        $branch = Branch::query()->active()->find($branchId);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your active branches.']);
        }

        $session->put(self::SESSION_KEY, $branch->id);

        return $branch;
    }
}
