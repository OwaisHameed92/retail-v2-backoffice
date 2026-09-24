<?php

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Enums\CompanyStatus;

/**
 * Everything CreateTenant needs: the business, its first branch (plus any more), how many tills, the owner login
 * and the plan.
 */
final readonly class NewTenant
{
    public const MAX_TILLS = 20;

    public function __construct(
        public CompanyDetails $company,
        public BranchDetails $branch,
        public int $tills,
        public string $ownerName,
        public string $ownerEmail,
        public CompanyStatus $status = CompanyStatus::Trial,
        /** Plan for the new tills (module 1.3); null = the portal default plan. */
        public ?string $planId = null,
        /** @var list<NewBranch> More shops, each with its tills (module 1.6 trial approval). */
        public array $moreBranches = [],
    ) {}

    public function totalTills(): int
    {
        return $this->tills + array_sum(array_map(fn (NewBranch $branch) => $branch->tills, $this->moreBranches));
    }
}
