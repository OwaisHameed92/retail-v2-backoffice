<?php

use App\Domain\Plans\Actions\UpdatePlan;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Validation\ValidationException;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planInput;

require_once __DIR__.'/PlanTestHelpers.php';

beforeEach(function () {
    $this->plan = Plan::factory()->create([
        'name' => 'Standard',
        'code' => 'standard',
        'description' => 'A plan.',
        'price_monthly' => '30.00',
        'price_yearly' => '300.00',
        'features' => [Feature::StockControl],
        'sort_order' => 10,
    ]);
});

it('updates a plan and records only the changed values', function () {
    $this->actingAs(planAdmin(), 'admin');

    app(UpdatePlan::class)->handle($this->plan, planInput(monthly: '32.50', features: [Feature::StockControl, Feature::AiAssistant]));

    expect($this->plan->fresh()->price_monthly)->toBe('32.50');

    $log = AuditLog::query()->where('action', 'plan.updated')->sole();

    expect($log->before)->toBe(['price_monthly' => '30.00', 'features' => ['stockControl']])
        ->and($log->after)->toBe(['price_monthly' => '32.50', 'features' => ['stockControl', 'aiAssistant']]);
});

it('writes nothing when no value changed', function () {
    $before = $this->plan->updated_at;

    $plan = app(UpdatePlan::class)->handle($this->plan, planInput(monthly: '30', yearly: '300.00'));

    expect($plan->wasChanged())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0)
        ->and($this->plan->fresh()->updated_at->equalTo($before))->toBeTrue();
});

it('allows a plan to keep its own code', function () {
    app(UpdatePlan::class)->handle($this->plan, planInput(name: 'Standard 2026', code: 'standard'));

    expect($this->plan->fresh()->name)->toBe('Standard 2026');
});

it('rejects a code used by another plan', function () {
    Plan::factory()->archived()->create(['code' => 'pro']);

    app(UpdatePlan::class)->handle($this->plan, planInput(code: 'pro'));
})->throws(ValidationException::class, 'Another plan already uses this code.');

it('refuses to edit an archived plan', function () {
    $this->plan->delete();

    app(UpdatePlan::class)->handle($this->plan, planInput(monthly: '99.00'));
})->throws(ValidationException::class, 'Restore Standard before editing it.');
