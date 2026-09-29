<?php

namespace Tests\Feature\TillHealth;

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillHealth\Actions\RefreshTillHealth;
use App\Domain\TillHealth\Models\TillHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Module 2.7 test helpers: a till that checked in at a given time, a shop's sync status, a refresh.
 */
trait TillHealthHelpers
{
    /** Bind the licence to a PC that last validated at `$at` (app 0.1.4, clock in step). */
    public function seen(Licence $licence, CarbonImmutable $at, array $extra = []): Licence
    {
        $at = $at->utc();
        $licence->forceFill([
            'status' => LicenceStatus::Active,
            'activated_at' => $at->subDays(30),
            'expires_at' => $at->addYear(),
            'device_id' => $licence->device_id ?? Ulid::new(),
            'device_name' => 'TILL-'.substr($licence->register_id, -2),
            'bound_at' => $at->subDays(30),
            'last_check_in_at' => $at,
            'last_validated_at' => $at,
            'last_app_version' => '0.1.4',
            'last_contract_version' => '1',
            'till_clock_skew_seconds' => 2,
            ...$extra,
        ])->save();

        return $licence->refresh();
    }

    /** @param  array<string, mixed>  $values */
    public function syncStatus(Branch $branch, array $values): void
    {
        DB::table('sync_branch_status')->updateOrInsert(['branch_id' => $branch->id], [
            'id' => DB::table('sync_branch_status')->where('branch_id', $branch->id)->value('id') ?? Ulid::new(),
            'company_id' => $branch->company_id,
            'created_at' => now(),
            'updated_at' => now(),
            ...$values,
        ]);
    }

    /** @return array{companies: int, tills: int, branches: int, raised: int, resolved: int} */
    public function refreshHealth(?array $companyIds = null): array
    {
        return app(RefreshTillHealth::class)->handle(CarbonImmutable::now(), $companyIds);
    }

    public function healthOfTill(Register $register): ?TillHealth
    {
        return TillHealth::withoutCompanyScope()->where('register_id', $register->id)->first();
    }

    /** Open alerts of a licence, as type values. */
    public function openAlerts(Licence $licence): array
    {
        return LicenceAlert::withoutCompanyScope()->where('licence_id', $licence->id)->open()->get()
            ->map(fn (LicenceAlert $alert) => $alert->type->value)->sort()->values()->all();
    }

    public function alertOf(Licence $licence, LicenceAlertType $type): ?LicenceAlert
    {
        return LicenceAlert::withoutCompanyScope()->where('licence_id', $licence->id)->where('type', $type->value)->latest('first_seen_at')->first();
    }
}
