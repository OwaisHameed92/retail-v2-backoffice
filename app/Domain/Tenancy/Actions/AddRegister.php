<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Events\RegisterAdded;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a till to a branch. Without a code it takes the lowest free one; without a name it is "Till <n>".
 * The first active till of a branch becomes its main till automatically.
 *
 * Dispatches RegisterAdded in the same transaction: module 1.3 issues the till's licence there (its plain key
 * waits in IssuedKeys for the caller: the welcome email or the admin's "Licence key created" dialog).
 */
class AddRegister
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Branch $branch, ?string $name = null, ?string $code = null, bool $makeMain = false): Register
    {
        return $this->tenancy->runAs($branch->company()->firstOrFail(), fn () => DB::transaction(function () use ($branch, $name, $code, $makeMain) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->getKey());

            if (! $branch->is_active) {
                throw ValidationException::withMessages(['code' => "{$branch->name} is inactive. Reactivate the branch before adding tills."]);
            }

            if ($code === null || $code === '') {
                $code = RegisterCodes::next($branch);
            } else {
                RegisterCodes::ensureUnique($branch, $code);
            }

            $name = trim((string) $name) !== '' ? trim((string) $name) : 'Till '.(int) $code;

            $register = Register::query()->create([
                'branch_id' => $branch->getKey(),
                'code' => $code,
                'name' => $name,
                'is_main_till' => false,
                'is_active' => true,
            ]);

            $current = Register::query()->where('branch_id', $branch->getKey())->where('is_main_till', true)->first();
            MainTill::normalise($branch, $makeMain ? $register : $current);
            $register->refresh();

            $this->audit->handle('register.created', $register, null, [
                'id' => $register->id,
                'branch_id' => $branch->getKey(),
                'code' => $register->code,
                'name' => $register->name,
                'is_main_till' => $register->is_main_till,
            ]);

            RegisterAdded::dispatch($register);

            return $register;
        }));
    }
}
