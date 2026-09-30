<?php

namespace App\Domain\Staff\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Staff\Models\StaffBranch;
use App\Domain\Staff\Support\StaffGuards;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes a till staff member (module 4.5): a soft delete, so every till gets a `D` at its next pull and their
 * past sales keep their name. The last active Owner cannot be removed.
 */
final class DeleteStaffMember
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly StaffGuards $guards,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $memberId): void
    {
        $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($memberId): void {
            $member = TillUser::query()->findOrFail($memberId);
            $this->guards->keepAnOwner($member, false);

            $member->delete();
            StaffBranch::query()->where('till_user_id', $member->id)->delete();
            $this->audit->handle('staff.deleted', $member, ['name' => $member->name, 'role_id' => $member->role_id]);
        }));
    }
}
