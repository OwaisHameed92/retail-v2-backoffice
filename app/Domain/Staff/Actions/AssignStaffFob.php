<?php

namespace App\Domain\Staff\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Staff\Support\StaffGuards;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Validation\ValidationException;

/**
 * Gives a till staff member an RFID sign-in fob, or swaps it for a new one (module 4.5). The code goes to every till
 * in the next pull and is never shown on the portal or written to the audit log.
 *
 * There is no "remove" here on purpose (ANSWERS-2026-09-29-b A.1): a blank `rfid` in a pull keeps the till's fob,
 * and on tills up to 0.1.8 it wiped it, so the portal never sends one (PullPayload::KEPT_WHEN_BLANK). A fob is
 * removed at the till; the till's push then clears it here too. To lock out a lost fob at once, deactivate the
 * staff member or give them a new fob.
 *
 *     app(AssignStaffFob::class)->handle($company, $memberId, '04A1B2C3');
 */
final class AssignStaffFob
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly StaffGuards $guards,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $memberId, string $rfid): TillUser
    {
        return $this->tenancy->runAs($company, function () use ($memberId, $rfid): TillUser {
            $member = TillUser::query()->findOrFail($memberId);
            $replaced = ($member->rfid ?? '') !== '';

            $member->forceFill(['rfid' => $this->guards->checkFob($rfid, $member->id)])->save();
            $this->audit->handle($replaced ? 'staff.fob_replaced' : 'staff.fob_assigned', $member, meta: ['name' => $member->name]);

            return $member;
        });
    }
}
