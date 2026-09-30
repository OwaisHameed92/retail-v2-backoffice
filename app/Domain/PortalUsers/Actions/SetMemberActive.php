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
 * Deactivates or reactivates a user's access to the business (module 4.1). A deactivated user keeps their role and
 * shop but cannot open this business's portal (their next page load signs them out of it). The last active owner
 * can never be deactivated, and nobody deactivates themselves.
 */
class SetMemberActive
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, User $member, bool $active, User $actor): void
    {
        MemberAccess::ensureNotSelf($actor, $member, 'You cannot deactivate yourself. Ask another owner.');

        DB::transaction(function () use ($company, $member, $active) {
            $membership = MemberAccess::requireMembership($company, $member);

            if ((bool) $membership->is_active === $active) {
                return;
            }

            if (! $active && $membership->role === CompanyRole::Owner->value) {
                CompanyOwners::ensureAnotherOwner($company, $member, "{$member->name} is the only owner and cannot be deactivated. Make someone else an owner first.");
            }

            $company->users()->updateExistingPivot($member->getKey(), ['is_active' => $active]);

            $this->audit->handle($active ? 'company.user_reactivated' : 'company.user_deactivated', $member,
                ['is_active' => ! $active], ['is_active' => $active],
                ['email' => $member->email, 'role' => $membership->role], companyId: $company->getKey());
        });
    }
}
