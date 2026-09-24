<?php

use App\Domain\Admin\Models\Admin;
use App\Domain\Plans\Actions\CreatePlan;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanStatus;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Validation\ValidationException;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planInput;

require_once __DIR__.'/PlanTestHelpers.php';

it('creates a plan with normalised values', function () {
    $plan = app(CreatePlan::class)->handle(planInput(
        name: '  Standard  ',
        code: ' STANDARD ',
        monthly: '30',
        yearly: '300.5',
        features: ['aiInsights', Feature::StockControl, 'stockControl', 'notAFeature'],
    ));

    $plan->refresh();

    expect($plan->name)->toBe('Standard')
        ->and($plan->code)->toBe('standard')
        ->and($plan->price_per_till_monthly)->toBe('30.00')
        ->and($plan->price_per_till_yearly)->toBe('300.50')
        ->and($plan->currency)->toBe('GBP')
        ->and($plan->trial_days)->toBe(7)
        ->and($plan->trial_grace_days)->toBe(3)
        ->and($plan->grace_days)->toBe(7)
        ->and($plan->featureValues())->toBe(['stockControl', 'aiInsights'])
        ->and($plan->status())->toBe(PlanStatus::Active)
        ->and(strlen($plan->id))->toBe(26);
});

it('records plan.created with the admin as actor', function () {
    $admin = planAdmin();
    $this->actingAs($admin, 'admin');

    $plan = app(CreatePlan::class)->handle(planInput());

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('plan.created')
        ->and($log->subject_id)->toBe($plan->id)
        ->and($log->actor_type)->toBe((new Admin)->getMorphClass())
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->company_id)->toBeNull()
        ->and($log->before)->toBeNull()
        ->and($log->after['price_per_till_monthly'])->toBe('30.00')
        ->and($log->after['features'])->toBe(['stockControl']);
});

it('rejects a code already used by another plan, archived ones included', function () {
    Plan::factory()->archived()->create(['code' => 'standard']);

    app(CreatePlan::class)->handle(planInput(code: 'standard'));
})->throws(ValidationException::class, 'Another plan already uses this code.');

it('writes no audit entry when creation fails', function () {
    Plan::factory()->create(['code' => 'standard']);

    try {
        app(CreatePlan::class)->handle(planInput(code: 'standard'));
    } catch (ValidationException) {
    }

    expect(AuditLog::query()->count())->toBe(0)
        ->and(Plan::query()->count())->toBe(1);
});
