<?php

namespace Database\Factories;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Licences for tests and demo data. The plain key is not kept: use the IssueLicence action when a test needs it.
 * company_id and branch_id are copied from the register.
 *
 * @extends Factory<Licence>
 */
class LicenceFactory extends Factory
{
    protected $model = Licence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = LicenceKey::generate();

        return [
            'register_id' => Register::factory(),
            'company_id' => fn (array $attributes) => Register::withoutCompanyScope()->whereKey($attributes['register_id'])->value('company_id'),
            'branch_id' => fn (array $attributes) => Register::withoutCompanyScope()->whereKey($attributes['register_id'])->value('branch_id'),
            'plan_id' => Plan::factory(),
            'key_hash' => $key->hash(),
            'key_last4' => $key->last4(),
            'status' => LicenceStatus::Issued,
            'features' => [],
            'grace_days' => 3,
        ];
    }

    public function forRegister(Register $register): static
    {
        return $this->state(fn () => ['register_id' => $register->id, 'company_id' => $register->company_id, 'branch_id' => $register->branch_id]);
    }

    public function onPlan(Plan $plan): static
    {
        return $this->state(fn () => ['plan_id' => $plan->id, 'features' => $plan->features]);
    }

    /** Activated `$daysAgo` days ago on a trial of `$trialDays` days. */
    public function trial(int $daysAgo = 2, int $trialDays = 7, int $graceDays = 3): static
    {
        return $this->state(function () use ($daysAgo, $trialDays, $graceDays) {
            $activated = CarbonImmutable::now()->subDays($daysAgo);

            return [
                'status' => LicenceStatus::Trial,
                'activated_at' => $activated,
                'trial_ends_at' => $activated->addDays($trialDays),
                'grace_days' => $graceDays,
                'device_id' => 'PC-'.strtoupper(fake()->bothify('####-????')),
                'device_name' => 'TILL-'.fake()->numberBetween(1, 9),
                'bound_at' => $activated,
                'last_check_in_at' => CarbonImmutable::now()->subHours(3),
                'last_app_version' => '1.0.0',
            ];
        });
    }

    /** Activated and paid until `$daysLeft` days from now. */
    public function paid(int $daysLeft = 20, int $graceDays = 7): static
    {
        return $this->trial(40)->state(fn () => [
            'status' => LicenceStatus::Active,
            'expires_at' => CarbonImmutable::now()->addDays($daysLeft),
            'grace_days' => $graceDays,
        ]);
    }
}
