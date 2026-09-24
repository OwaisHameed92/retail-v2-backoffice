<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Events\RegisterDeactivated;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * Takes a till out of use. If it was the main till, the active till with the lowest code takes over.
 * Dispatches RegisterDeactivated: module 1.3 suspends the till's licence ("Till deactivated").
 */
class DeactivateRegister
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Register $register): Register
    {
        return $this->tenancy->runAs($register->company()->firstOrFail(), fn () => DB::transaction(function () use ($register) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($register->branch_id);
            $register->refresh();

            if (! $register->is_active) {
                return $register;
            }

            $wasMain = $register->is_main_till;
            $register->is_active = false;
            $register->is_main_till = false;
            $register->save();

            $newMain = MainTill::normalise($branch);

            $this->audit->handle('register.deactivated', $register, ['is_active' => true, 'is_main_till' => $wasMain], ['is_active' => false, 'is_main_till' => false], array_filter([
                'new_main_register_id' => $wasMain ? $newMain?->id : null,
            ]));

            RegisterDeactivated::dispatch($register);

            return $register;
        }));
    }
}
