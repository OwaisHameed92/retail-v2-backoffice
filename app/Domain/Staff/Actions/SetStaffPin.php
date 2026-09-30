<?php

namespace App\Domain\Staff\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Staff\Support\StaffGuards;
use App\Domain\Staff\Support\TillPinHasher;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Validation\ValidationException;

/**
 * Sets or resets a till staff member's PIN (module 4.5, contract §10.7). Only the PBKDF2 hash is kept
 * (TillPinHasher) and sent to every till in the next pull; the PIN is never stored, logged, audited or shown.
 * A PIN another active colleague uses, or an easy one (1111, 1234), is refused.
 *
 *     app(SetStaffPin::class)->handle($company, $memberId, '4821');
 */
final class SetStaffPin
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly StaffGuards $guards,
        private readonly TillPinHasher $hasher,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $memberId, string $pin): TillUser
    {
        return $this->tenancy->runAs($company, function () use ($memberId, $pin): TillUser {
            $member = TillUser::query()->findOrFail($memberId);
            $this->guards->checkPin($pin, $member->id);

            $member->forceFill(['pin_hash' => $this->hasher->hash($pin)])->save();
            $this->audit->handle('staff.pin_changed', $member, meta: ['name' => $member->name]);

            return $member;
        });
    }
}
