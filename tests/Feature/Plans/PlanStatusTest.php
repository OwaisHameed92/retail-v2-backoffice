<?php

use App\Domain\Plans\Enums\PlanStatus;
use App\Domain\Plans\Models\Plan;

it('derives the plan status from its flags', function (bool $active, bool $public, bool $archived, PlanStatus $status) {
    $plan = new Plan(['is_active' => $active, 'is_public' => $public]);
    $plan->deleted_at = $archived ? now() : null;

    expect($plan->status())->toBe($status);
})->with([
    'active' => [true, true, false, PlanStatus::Active],
    'hidden' => [true, false, false, PlanStatus::Hidden],
    'inactive and public' => [false, true, false, PlanStatus::Inactive],
    'inactive and hidden' => [false, false, false, PlanStatus::Inactive],
    'archived wins' => [true, true, true, PlanStatus::Archived],
]);

it('filters plans by status in queries', function (PlanStatus $status, int $expected) {
    Plan::factory()->create();
    Plan::factory()->hidden()->count(2)->create();
    Plan::factory()->inactive()->count(3)->create();
    Plan::factory()->archived()->count(4)->create();

    expect(Plan::query()->whereStatus($status)->count())->toBe($expected);
})->with([
    [PlanStatus::Active, 1],
    [PlanStatus::Hidden, 2],
    [PlanStatus::Inactive, 3],
    [PlanStatus::Archived, 4],
]);
