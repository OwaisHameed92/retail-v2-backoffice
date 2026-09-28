<?php

use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planPayload;

require_once __DIR__.'/PlanTestHelpers.php';

test('a plan saves its setup fee in pounds, defaulting to none', function () {
    $this->actingAs(planAdmin(), 'admin');

    $this->post(route('admin.plans.store'), planPayload())->assertRedirect();
    expect(Plan::query()->where('code', 'standard')->sole()->setup_fee)->toBe('0.00');

    $this->post(route('admin.plans.store'), planPayload(['code' => 'pro', 'name' => 'Pro', 'setup_fee' => '£349']))->assertRedirect();
    $pro = Plan::query()->where('code', 'pro')->sole();
    expect($pro->setup_fee)->toBe('349.00');

    $this->put(route('admin.plans.update', $pro), planPayload(['code' => 'pro', 'name' => 'Pro', 'setup_fee' => '299.50']))->assertRedirect();
    expect($pro->refresh()->setup_fee)->toBe('299.50')
        ->and(AuditLog::query()->where('action', 'plan.updated')->latest('id')->first()->after)->toMatchArray(['setup_fee' => '299.50']);
});

test('the setup fee must be pounds with up to 2 decimal places', function (string $value) {
    $this->actingAs(planAdmin(), 'admin');

    $this->post(route('admin.plans.store'), planPayload(['setup_fee' => $value]))->assertSessionHasErrors('setup_fee');
})->with(['-5', 'ten', '1.999', '100000']);
