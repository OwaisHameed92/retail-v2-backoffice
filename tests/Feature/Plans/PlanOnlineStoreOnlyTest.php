<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Actions\ChangeCompanyPlan;
use App\Domain\Licensing\Actions\ChangeLicencePlan;
use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Data\PlanData;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planPayload;

require_once __DIR__.'/PlanTestHelpers.php';

/*
 * OS-13 (owner 2026-10-26): a recurring plan can be "Online store only (no tills)": it includes the online store, its
 * monthly fee is the online store fee (once per business), no till prices, features or handheld app, and no till
 * licence is ever issued on it. Hidden on PK.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
});

function oooPayload(array $overrides = []): array
{
    return planPayload(['code' => 'web-only', 'name' => 'Online store only', 'billing_type' => 'recurringOnly', 'setup_fee' => '0',
        'price_monthly' => '', 'price_yearly' => '', 'online_store_only' => true, 'online_store_monthly_price' => '30', ...$overrides]);
}

it('adds the column without changing any existing plan', function () {
    $plan = Plan::factory()->create(['code' => 'standard']);

    expect($plan->fresh()->online_store_only)->toBeFalse()->and($plan->fresh()->isOnlineStoreOnly())->toBeFalse();
});

it('saves an online store only plan from the form: the store included, no till prices, features or handheld; audited', function () {
    $this->actingAs(planAdmin(AdminRole::Owner), 'admin');
    $this->post('/admin/plans', oooPayload(['features' => ['loyalty'], 'includes_handheld' => true]))->assertRedirect()->assertSessionHasNoErrors();

    $plan = Plan::query()->where('code', 'web-only')->sole();
    expect($plan->online_store_only)->toBeTrue()->and($plan->isOnlineStoreOnly())->toBeTrue()
        ->and($plan->includes_online_store)->toBeTrue()->and($plan->online_store_monthly_price)->toBe('30.00')
        ->and([$plan->price_monthly, $plan->price_yearly, $plan->setup_fee])->toBe(['0.00', '0.00', '0.00'])
        ->and($plan->featureValues())->toBe([])->and($plan->includes_handheld)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'plan.created')->sole()->after)->toMatchArray(['online_store_only' => true, 'online_store_monthly_price' => '30.00'])
        ->and(PlanData::row($plan))->toMatchArray(['onlineStoreOnly' => true, 'onlineStorePrice' => '30.00', 'includesOnlineStore' => true]);

    $this->get("/admin/plans/{$plan->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('plan.onlineStoreOnly', true));
});

it('keeps a setup fee when the admin sets one (setup + monthly); refuses setup only', function () {
    $this->actingAs(planAdmin(AdminRole::Owner), 'admin');
    $this->post('/admin/plans', oooPayload(['code' => 'web-setup', 'billing_type' => 'setupAndRecurring', 'setup_fee' => '99']))->assertSessionHasNoErrors();
    expect(Plan::query()->where('code', 'web-setup')->sole()->setup_fee)->toBe('99.00');

    $this->post('/admin/plans', oooPayload(['code' => 'web-bad', 'billing_type' => 'setupOnly', 'setup_fee' => '99']))->assertSessionHasErrors('online_store_only');
    expect(Plan::query()->where('code', 'web-bad')->exists())->toBeFalse();
});

it('cannot be switched to or from "online store only" once a business uses the plan', function () {
    $this->actingAs(planAdmin(AdminRole::Owner), 'admin');
    $this->post('/admin/plans', oooPayload())->assertSessionHasNoErrors();
    $plan = Plan::query()->where('code', 'web-only')->sole();
    $company = $this->tenant('Rosa Deli', 1);
    Licence::withoutCompanyScope()->where('company_id', $company->id)->delete();
    $company->forceFill(['plan_id' => $plan->id])->save();

    expect($plan->isInUse())->toBeTrue();
    $this->put("/admin/plans/{$plan->id}", oooPayload(['online_store_only' => false, 'price_monthly' => '30', 'price_yearly' => '300']))
        ->assertSessionHasErrors('online_store_only');
    expect($plan->fresh()->online_store_only)->toBeTrue();
});

it('is never offered for till licences: left out of the licence plan pickers, refused by every licence action', function () {
    $standard = $this->standardPlan();
    $web = Plan::factory()->create(['code' => 'web-only', 'billing_type' => 'recurringOnly', 'price_monthly' => '0', 'price_yearly' => '0',
        'includes_online_store' => true, 'online_store_only' => true, 'online_store_monthly_price' => '30']);
    $company = $this->tenant('Khan Mini Mart', 1);
    $licence = Licence::withoutCompanyScope()->where('company_id', $company->id)->firstOrFail();

    expect(collect(LicenceData::planOptions())->pluck('value')->all())->toContain($standard->id)->not->toContain($web->id)
        ->and(collect(LicenceData::planOptions(withOnlineStoreOnly: true))->pluck('value')->all())->toContain($web->id);

    $register = Register::factory()->forBranch($this->allowTills($this->branchOf($company)))->create();
    expect(fn () => app(IssueLicence::class)->handle($register, $web))->toThrow(ValidationException::class, 'online store only plan')
        ->and(fn () => app(ChangeLicencePlan::class)->handle($licence, $web))->toThrow(ValidationException::class, 'online store only plan')
        ->and(fn () => app(ChangeCompanyPlan::class)->handle($company, $web, true))->toThrow(ValidationException::class, 'online store only plan')
        ->and($licence->fresh()->plan_id)->toBe($standard->id);
});

it('is refused on Pakistan, where there are no online stores', function () {
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
    $this->actingAs(planAdmin(AdminRole::Owner), 'admin');

    $this->post('/admin/plans', oooPayload())->assertSessionHasErrors('includes_online_store');
    expect(Plan::query()->where('code', 'web-only')->exists())->toBeFalse();
});
