<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a till its branch's main till (the one that syncs with the portal). The old main till becomes secondary.
 */
class SetMainTill
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

            if (! $register->is_active) {
                throw ValidationException::withMessages(['register' => "{$register->name} is inactive. Reactivate it before making it the main till."]);
            }

            if ($register->is_main_till) {
                return $register;
            }

            $previous = Register::query()->where('branch_id', $branch->getKey())->where('is_main_till', true)->value('id');

            MainTill::normalise($branch, $register);
            $register->refresh();

            $this->audit->handle('register.main_till_changed', $register, ['main_register_id' => $previous], ['main_register_id' => $register->id], [
                'branch_id' => $branch->getKey(),
            ]);

            return $register;
        }));
    }
}
