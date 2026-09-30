<?php

namespace App\Domain\PortalUsers\Actions;

use App\Domain\PortalUsers\Support\MemberAccess;
use App\Domain\Tenancy\Actions\RemoveCompanyUser;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * An owner removes someone from the business's portal (module 4.1). Their account stays (they may belong to other
 * businesses). Uses the admin's RemoveCompanyUser, so the last-owner guard and the audit entry are the same; nobody
 * removes themselves here.
 */
class RemoveMember
{
    public function __construct(private readonly RemoveCompanyUser $remove) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, User $member, User $actor): void
    {
        MemberAccess::ensureNotSelf($actor, $member, 'You cannot remove yourself. Ask another owner.');

        $this->remove->handle($company, $member);
    }
}
