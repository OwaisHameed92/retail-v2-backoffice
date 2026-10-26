<?php

use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\OnlineStore\Support\OnlineStoreOnly;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\ChangePlanHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\OnlineStore\OnlineStoreOnlyHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * OS-13: Change plan with the online store only offer. To a plan with tills: allowed, the preview says what happens to
 * the store and the tills; a plan without the store switches it off. To online store only: only for a business with no
 * live till.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class, ChangePlanHelpers::class, OnlineStoreOnlyHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    config(['mail.default' => 'array']);
    $this->fakeGoCardless();
    $this->atLondon('2026-10-26 10:00');
    $this->setVat(true);
    config(['online_store.enabled' => true]);
    $this->standardPlan();
    $this->webPlan = $this->webOnlyPlan('30.00');
});

test('a business with live tills cannot move to an online store only plan: the preview and the change are blocked', function () {
    $company = $this->directDebitTenant(tills: 2);

    $preview = $this->previewPlan($company, $this->webPlan)->assertOk()->json();
    expect($preview['blocked'])->toContain('online store only plan: it has no tills');

    $this->changePlan($company, $this->webPlan, ['store_template' => 'freshMarket'])->assertSessionHasErrors('plan_id');
    expect($company->refresh()->plan_id)->not->toBe($this->webPlan->id);
});

test('a business without live tills can move to online store only: its store is made and its fee is the store line', function () {
    $company = $this->directDebitTenant(tills: 1);

    foreach ($this->licencesOf($company) as $licence) {
        app(RevokeLicence::class)->handle($licence, 'Shop closed');
    }

    $preview = $this->previewPlan($company, $this->webPlan)->assertOk()->json();
    expect($preview['blocked'])->toBeNull()->and($preview['onlineStore']['needsTemplate'])->toBeTrue()
        ->and(implode(' ', $preview['licences']['lines']))->toContain('no licence key is issued on it');

    $this->changePlan($company, $this->webPlan, ['store_template' => 'butcher', 'setup_fee' => '0'])->assertSessionHasNoErrors();
    $company->refresh();
    expect($company->plan_id)->toBe($this->webPlan->id)
        ->and($this->storeOf($company)->sells_without_tills)->toBeTrue()
        ->and(OnlineStoreSwitch::availableFor($company))->toBeTrue()
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'])->toBe('36.00');
});

test('to a plan with tills but without the store: the preview says it is switched off; the store closes and nothing is billed until tills', function () {
    $company = $this->webOnlyBusiness($this->webPlan);
    $plain = $this->cpPlan('Monthly plain', PlanBillingType::RecurringOnly, '0.00', '25.00', [Feature::Loyalty, Feature::CloudSync]);

    $preview = $this->previewPlan($company, $plain)->assertOk()->json();
    expect($preview['onlineStore']['change'])->toBe('off')
        ->and(implode(' ', $preview['onlineStore']['lines']))->toContain('Switched off: Monthly plain does not include the online store');

    $this->changePlan($company, $plain)->assertSessionHasNoErrors();
    $company->refresh();
    expect($this->storeOf($company)->addon_on)->toBeFalse()
        ->and(OnlineStoreSwitch::availableFor($company))->toBeFalse()
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'])->toBe('0.00')
        ->and(OnlineStoreOnly::sellsWithoutTills($company))->toBeTrue();
});

test('to a plan with the store but no cloud sync: the preview warns that the store needs it once a till is live', function () {
    $company = $this->webOnlyBusiness($this->webPlan);
    $noSync = $this->cpPlan('Monthly basic + web', PlanBillingType::RecurringOnly, '0.00', '20.00', [Feature::Loyalty]);
    $noSync->forceFill(['includes_online_store' => true])->save();

    $lines = implode(' ', $this->previewPlan($company, $noSync)->assertOk()->json('onlineStore.lines'));
    expect($lines)->toContain('Careful: Monthly basic + web does not include cloud sync')
        ->and($lines)->toContain('Included in the new amount above');
});
