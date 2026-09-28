<?php

use App\Domain\Plans\Models\Plan;
use Database\Seeders\PlanSeeder;

it('seeds Standard and Pro, and can run twice', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(PlanSeeder::class);

    $standard = Plan::query()->where('code', 'standard')->sole();
    $pro = Plan::query()->where('code', 'pro')->sole();

    expect(Plan::query()->count())->toBe(2)
        ->and($standard->price_monthly)->toBe('30.00')
        ->and($standard->price_yearly)->toBe('300.00')
        ->and($standard->featureValues())->not->toContain('aiAssistant')
        ->and($pro->price_monthly)->toBe('45.00')
        ->and($pro->price_yearly)->toBe('450.00')
        ->and($pro->featureValues())->toContain('aiAssistant', 'aiInsights')
        ->and($pro->features)->toHaveCount(10);
});
