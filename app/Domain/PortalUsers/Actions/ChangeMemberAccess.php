<?php

namespace App\Domain\PortalUsers\Actions;

use App\Domain\PortalUsers\Support\MemberAccess;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Actions\CompanyOwners;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An owner changes another user's role and shop (module 4.1). The last active owner can never be demoted, an owner
 * always sees every shop, and nobody changes their own access here (another owner must).
 */
class ChangeMemberAccess
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, User $member, CompanyRole $role, ?string $branchId, User $actor): void
    {
        MemberAccess::ensureNotSelf($actor, $member, 'You cannot change your own role. Ask another owner.');
        MemberAccess::ensureBranchFits($company, $role, $branchId);

        DB::transaction(function () use ($company, $member, $role, $branchId) {
            $membership = MemberAccess::requireMembership($company, $member);

            if ($membership->role === $role->value && $membership->branch_id === $branchId) {
                return;
            }

            if ($membership->role === CompanyRole::Owner->value && $role !== CompanyRole::Owner && (bool) $membership->is_active) {
                CompanyOwners::ensureAnotherOwner($company, $member, "{$member->name} is the only owner. Make someone else an owner first.");
            }

            $company->users()->updateExistingPivot($member->getKey(), ['role' => $role->value, 'branch_id' => $branchId]);

            $this->audit->handle('company.user_access_changed', $member,
                ['role' => $membership->role, 'branch_id' => $membership->branch_id],
                ['role' => $role->value, 'branch_id' => $branchId],
                ['email' => $member->email], companyId: $company->getKey());
        });
    }
}
