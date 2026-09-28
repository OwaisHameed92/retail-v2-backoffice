<?php

namespace Tests\Feature\Licensing;

use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Data\IssuedLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the module 1.3 tests. Use with `uses(TenantTestHelpers::class, LicensingTestHelpers::class)`.
 */
trait LicensingTestHelpers
{
    /** The portal default plan (code "standard"): 7-day trial, 3 trial grace days, 7 paid grace days. */
    public function standardPlan(): Plan
    {
        return Plan::query()->where('code', 'standard')->first() ?? Plan::factory()->create([
            'name' => 'Standard',
            'code' => 'standard',
            'trial_days' => 7,
            'trial_grace_days' => 3,
            'grace_days' => 7,
            'features' => [Feature::Loyalty, Feature::Promotions],
            'sort_order' => 10,
        ]);
    }

    public function proPlan(): Plan
    {
        return Plan::query()->where('code', 'pro')->first() ?? Plan::factory()->create([
            'name' => 'Pro',
            'code' => 'pro',
            'trial_days' => 14,
            'trial_grace_days' => 2,
            'grace_days' => 10,
            'features' => Feature::cases(),
            'sort_order' => 20,
        ]);
    }

    /** A tenant created through CreateTenant with a plan present, so every till has its licence. */
    public function licensedTenant(string $name = 'Khan Mini Mart', int $tills = 2, string $code = 'LDS'): Company
    {
        $this->standardPlan();

        return $this->tenant($name, $tills, $code);
    }

    public function licenceOf(Register $register): Licence
    {
        return Licence::withoutCompanyScope()->live()->where('register_id', $register->id)->firstOrFail();
    }

    /** The first till (01 in LDS) and its licence. */
    public function firstLicence(Company $company, string $branch = 'LDS'): Licence
    {
        return $this->licenceOf($this->registerOf($this->branchOf($company, $branch), '01'));
    }

    public function issue(Register $register, ?Plan $plan = null): IssuedLicence
    {
        return app(IssueLicence::class)->handle($register, $plan);
    }

    /** What module 1.5 will do on first activation: bind the PC and start the trial. */
    public function activate(Licence $licence, ?CarbonImmutable $at = null, string $deviceId = 'PC-0001', string $deviceName = 'FRONT-TILL'): Licence
    {
        $at ??= CarbonImmutable::now();
        $plan = $licence->plan;

        $licence->forceFill([
            'status' => LicenceStatus::Trial,
            'activated_at' => $at,
            'trial_ends_at' => $at->addDays($plan->trial_days ?? 7),
            'grace_days' => $plan->trial_grace_days ?? 3,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'bound_at' => $at,
        ])->save();

        return $licence->refresh();
    }

    /** Every row of the tables that must never hold a plain key, as one string. */
    public function storedText(): string
    {
        $text = '';

        foreach (['licences', 'audit_logs', 'email_logs', 'companies', 'jobs', 'failed_jobs'] as $table) {
            $text .= json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR);
        }

        return $text;
    }

    /**
     * The 16-character bodies of every key in a text (formatted keys), for "never stored" checks.
     *
     * @return list<string>
     */
    public function keysIn(string $text): array
    {
        preg_match_all('/SSP-([0-9A-Z]{4})-([0-9A-Z]{4})-([0-9A-Z]{4})-([0-9A-Z]{4})/', $text, $matches, PREG_SET_ORDER);

        return array_values(array_unique(array_map(fn (array $m) => $m[0], $matches)));
    }
}
