<?php

namespace App\Domain\Tenancy\Data;

use App\Domain\Licensing\Data\BranchLicenceSettings;
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
        /** Module 1.11: licence settings of the first branch (tills allowed, kind, length, features); the further
         * branches get the same with their own tills allowed. Null = the defaults (plan trial and features). */
        public ?BranchLicenceSettings $licence = null,
        /** Module 1.11: may run more than one branch, and how many. On by itself with more than one shop. */
        public bool $multiBranch = false,
        public int $maxBranches = 1,
    ) {}

    public function totalTills(): int
    {
        return $this->tills + array_sum(array_map(fn (NewBranch $branch) => $branch->tills, $this->moreBranches));
    }
}
