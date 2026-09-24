<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Events\RegisterReactivated;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a till back into use. It becomes the main till only if the branch has no other active till.
 * Dispatches RegisterReactivated: module 1.3 lifts a "Till deactivated" licence suspension.
 */
class ReactivateRegister
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Register $register): Register
    {
        return $this->tenancy->runAs($register->company()->firstOrFail(), fn () => DB::transaction(function () use ($register) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($register->branch_id);
            $register->refresh();

            if ($register->is_active) {
                return $register;
            }

            if (! $branch->is_active) {
                throw ValidationException::withMessages(['register' => "{$branch->name} is inactive. Reactivate the branch first."]);
            }

            $register->is_active = true;
            $register->save();

            MainTill::normalise($branch);
            $register->refresh();

            $this->audit->handle('register.reactivated', $register, ['is_active' => false], ['is_active' => true, 'is_main_till' => $register->is_main_till]);

            RegisterReactivated::dispatch($register);

            return $register;
        }));
    }
}
