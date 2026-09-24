<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames a till or changes its code. Use SetMainTill to change the main till.
 */
class UpdateRegister
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Register $register, string $name, string $code): Register
    {
        return $this->tenancy->runAs($register->company()->firstOrFail(), fn () => DB::transaction(function () use ($register, $name, $code) {
            $name = trim($name);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Enter a name for the till.']);
            }

            if ($code !== $register->code) {
                RegisterCodes::ensureUnique(Branch::query()->findOrFail($register->branch_id), $code, $register);
            }

            $register->fill(['name' => $name, 'code' => $code]);

            [$before, $after] = AuditChanges::of($register);

            if ($after === []) {
                return $register;
            }

            $register->save();

            $this->audit->handle('register.updated', $register, $before, $after);

            return $register;
        }));
    }
}
