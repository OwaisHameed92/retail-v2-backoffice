<?php

use App\Domain\Plans\Data\PlanData;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\Money;
use Illuminate\Support\Facades\DB;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planPayload;

require_once __DIR__.'/PlanTestHelpers.php';

it('stores prices exactly as entered, to the penny', function (string $entered, string $stored) {
    $this->actingAs(planAdmin(), 'admin')
        ->post('/admin/plans', planPayload(['price_monthly' => $entered, 'price_yearly' => $entered]))
        ->assertSessionHasNoErrors();

    $plan = Plan::query()->sole();

    expect($plan->price_monthly)->toBe($stored)
        ->and($plan->price_yearly)->toBe($stored)
        ->and(PlanData::row($plan)['priceMonthly'])->toBe($stored);
})->with([
    'whole pounds' => ['30', '30.00'],
    'one decimal' => ['29.9', '29.90'],
    'pennies' => ['29.99', '29.99'],
    'penny' => ['0.01', '0.01'],
    'free' => ['0', '0.00'],
    'pound sign and comma' => ['£1,200.50', '1200.50'],
    'largest allowed' => ['99999.99', '99999.99'],
]);

it('accepts JSON numbers without float noise', function () {
    $this->actingAs(planAdmin(), 'admin')
        ->postJson('/admin/plans', planPayload(['price_monthly' => 29.99, 'price_yearly' => 300]))
        ->assertRedirect();

    $plan = Plan::query()->sole();
    expect($plan->price_monthly)->toBe('29.99')
        ->and($plan->price_yearly)->toBe('300.00');
});

it('reads prices back at two decimal places whatever the database driver returns', function () {
    $plan = Plan::factory()->create(['price_monthly' => '45', 'price_yearly' => '450.5']);

    $row = DB::table('plans')->where('id', $plan->id)->first();

    // SQLite returns "45", MySQL "45.00": the value is exact either way and the cast fixes the scale.
    expect(Money::equals($row->price_monthly, '45.00'))->toBeTrue()
        ->and(Money::equals($row->price_yearly, '450.50'))->toBeTrue()
        ->and($plan->fresh()->price_monthly)->toBe('45.00')
        ->and($plan->fresh()->price_yearly)->toBe('450.50');
});

it('rounds half away from zero when set in code', function () {
    $plan = Plan::factory()->make(['price_monthly' => 1.005, 'price_yearly' => '2.675']);

    expect($plan->price_monthly)->toBe('1.01')
        ->and($plan->price_yearly)->toBe('2.68');
});

it('works out the yearly saving with decimal maths', function (string $monthly, string $yearly, string $saving, ?int $percent) {
    $plan = Plan::factory()->make(['price_monthly' => $monthly, 'price_yearly' => $yearly]);

    expect(PlanData::yearlySaving($plan))->toBe($saving)
        ->and(PlanData::yearlySavingPercent($plan))->toBe($percent);
})->with([
    'standard' => ['30.00', '300.00', '60.00', 17],
    'pro' => ['45.00', '450.00', '90.00', 17],
    'no saving' => ['10.00', '120.00', '0.00', 0],
    'dearer yearly' => ['10.00', '130.00', '-10.00', -8],
    'pennies' => ['0.10', '1.10', '0.10', 8],
    'free monthly' => ['0.00', '0.00', '0.00', null],
]);
