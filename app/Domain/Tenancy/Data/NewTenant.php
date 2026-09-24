<?php

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Enums\CompanyStatus;

/**
 * Everything CreateTenant needs: the business, its first branch, how many tills and the owner login.
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
    ) {}
}
