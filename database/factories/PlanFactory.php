<?php

namespace Database\Factories;

use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'code' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(),
            'price_per_till_monthly' => '30.00',
            'price_per_till_yearly' => '300.00',
            'currency' => Plan::CURRENCY,
            'trial_days' => Plan::DEFAULT_TRIAL_DAYS,
            'trial_grace_days' => Plan::DEFAULT_TRIAL_GRACE_DAYS,
            'grace_days' => Plan::DEFAULT_GRACE_DAYS,
            'features' => [Feature::StockControl, Feature::CashOffice],
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_active' => true, 'is_public' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['deleted_at' => now()]);
    }

    /**
     * @param  list<Feature>  $features
     */
    public function features(array $features): static
    {
        return $this->state(fn () => ['features' => $features]);
    }
}
