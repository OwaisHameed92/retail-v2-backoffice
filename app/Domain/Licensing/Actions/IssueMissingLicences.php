<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\IssuedLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a licence for every active till (in an active branch) of a company that has no live licence: tills
 * added before a plan existed, or whose licence was revoked. All or nothing.
 */
class IssueMissingLicences
{
    public function __construct(private readonly IssueLicence $issueLicence) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, ?Plan $plan = null): IssuedLicences
    {
        if ($company->isCancelled()) {
            throw ValidationException::withMessages(['status' => "{$company->name} is cancelled. Reinstate it before issuing licences."]);
        }

        $plan ??= DefaultPlan::for($company);

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => 'There is no active plan to licence tills with. Create a plan first.']);
        }

        return DB::transaction(function () use ($company, $plan) {
            $issued = self::unlicensedTills($company)->map(fn (Register $register) => $this->issueLicence->handle($register, $plan));

            return new IssuedLicences($issued->values()->all());
        });
    }

    /**
     * Active tills in active branches without a live licence, by branch then till code.
     *
     * @return Collection<int, Register>
     */
    public static function unlicensedTills(Company $company): Collection
    {
        return Register::withoutCompanyScope()
            ->select('registers.*')
            ->join('branches', 'branches.id', '=', 'registers.branch_id')
            ->where('registers.company_id', $company->id)
            ->where('registers.is_active', true)
            ->where('branches.is_active', true)
            ->whereNull('branches.deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('licences')
                ->whereColumn('licences.live_register_id', 'registers.id'))
            ->orderBy('branches.name')
            ->orderBy('registers.code')
            ->get();
    }

    public static function countUnlicensed(Company $company): int
    {
        return self::unlicensedTills($company)->count();
    }
}
