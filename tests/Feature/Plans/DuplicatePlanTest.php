<?php

use App\Domain\Plans\Actions\DuplicatePlan;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanStatus;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;

it('copies a plan as an inactive, hidden plan with a free code', function () {
    $source = Plan::factory()->create([
        'name' => 'Pro',
        'code' => 'pro',
        'price_monthly' => '45.00',
        'price_yearly' => '450.00',
        'trial_days' => 14,
        'features' => [Feature::AssistQuestions, Feature::Assist],
    ]);

    $copy = app(DuplicatePlan::class)->handle($source)->refresh();

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->name)->toBe('Pro (copy)')
        ->and($copy->code)->toBe('pro-copy')
        ->and($copy->price_monthly)->toBe('45.00')
        ->and($copy->price_yearly)->toBe('450.00')
        ->and($copy->trial_days)->toBe(14)
        ->and($copy->featureValues())->toBe(['assist_questions', 'assist'])
        ->and($copy->status())->toBe(PlanStatus::Inactive)
        ->and($copy->is_public)->toBeFalse()
        ->and($source->fresh()->status())->toBe(PlanStatus::Active);
});

it('numbers further copies and skips codes used by archived plans', function () {
    $source = Plan::factory()->create(['code' => 'pro']);
    Plan::factory()->archived()->create(['code' => 'pro-copy']);

    $second = app(DuplicatePlan::class)->handle($source);
    $third = app(DuplicatePlan::class)->handle($source);

    expect($second->code)->toBe('pro-copy-2')
        ->and($third->code)->toBe('pro-copy-3');
});

it('keeps long names and codes within their limits', function () {
    $source = Plan::factory()->create(['name' => str_repeat('N', 100), 'code' => str_repeat('a', 50)]);

    $copy = app(DuplicatePlan::class)->handle($source);

    expect(mb_strlen($copy->name))->toBeLessThanOrEqual(100)
        ->and(strlen($copy->code))->toBeLessThanOrEqual(50)
        ->and($copy->code)->toEndWith('-copy');
});

it('can copy an archived plan', function () {
    $source = Plan::factory()->archived()->create(['code' => 'legacy']);

    $copy = app(DuplicatePlan::class)->handle($source);

    expect($copy->trashed())->toBeFalse()
        ->and($copy->code)->toBe('legacy-copy');
});

it('records plan.duplicated on the copy with the source', function () {
    $source = Plan::factory()->create(['name' => 'Pro']);

    $copy = app(DuplicatePlan::class)->handle($source);

    $log = AuditLog::query()->sole();
    expect($log->action)->toBe('plan.duplicated')
        ->and($log->subject_id)->toBe($copy->id)
        ->and($log->meta)->toBe(['source_plan_id' => $source->id, 'source_plan_name' => 'Pro'])
        ->and($log->after['is_active'])->toBeFalse();
});
