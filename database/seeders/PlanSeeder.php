<?php

namespace Database\Seeders;

use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Local demo plans: Standard and Pro (Standard plus the AI features). Local and testing only; production plans
 * are created on the admin screens so they have an audit trail.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $core = array_values(array_filter(Feature::cases(), fn (Feature $feature) => ! $feature->isAi()));

        $plans = [
            [
                'code' => 'standard',
                'name' => 'Standard',
                'description' => 'Everything a convenience store needs to run the till and the back office.',
                'price_per_till_monthly' => '30.00',
                'price_per_till_yearly' => '300.00',
                'features' => $core,
                'sort_order' => 10,
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'Standard plus the AI assistant and AI insights.',
                'price_per_till_monthly' => '45.00',
                'price_per_till_yearly' => '450.00',
                'features' => Feature::cases(),
                'sort_order' => 20,
            ],
        ];

        foreach ($plans as $attributes) {
            Plan::withTrashed()->updateOrCreate(['code' => $attributes['code']], $attributes + [
                'currency' => Plan::CURRENCY,
                'trial_days' => Plan::DEFAULT_TRIAL_DAYS,
                'trial_grace_days' => Plan::DEFAULT_TRIAL_GRACE_DAYS,
                'grace_days' => Plan::DEFAULT_GRACE_DAYS,
                'is_active' => true,
                'is_public' => true,
            ]);
        }
    }
}
