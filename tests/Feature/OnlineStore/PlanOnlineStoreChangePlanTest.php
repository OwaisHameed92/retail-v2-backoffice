<?php

use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\OnlineStore\Actions\SetOnlineStoreAddOn;
use App\Domain\OnlineStore\Data\OnlineStoreAddOnInput;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\ChangePlanHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * OS-7: Change plan follows the new plan's online store. A plan with it switches the add-on on (template asked when the
 * business has no store); a plan without it switches it off (store offline, line stops), also an add-on switched on by
 * hand. The preview says so and its new amount includes the website line.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class, ChangePlanHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    config(['mail.default' => 'array']);
    $this->fakeGoCardless();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    config(['online_store.enabled' => true]);
});

/** A Direct Debit business on Standard (2 tills × £25 + VAT = £60 a month), its tills with cloud sync, mandate set up. */
function oscBusiness(object $test): Company
{
    $company = $test->directDebitTenant(tills: 2);

    foreach ($test->licencesOf($company) as $licence) {
        $licence->forceFill(['features' => [Feature::Loyalty->value, Feature::CloudSync->value]])->save();
    }
    $test->setUpMandate($company);

    return $company->refresh();
}

/** Same price per till as Standard, with the online store (£20 default unless given). */
function oscStorePlan(object $test, ?string $price = null): Plan
{
    $plan = $test->cpPlan('Monthly + website', PlanBillingType::RecurringOnly, '0.00', '25.00', [Feature::Loyalty, Feature::CloudSync]);
    $plan->forceFill(['includes_online_store' => true, 'online_store_monthly_price' => $price])->save();

    return $plan->refresh();
}

function oscPlainPlan(object $test): Plan
{
    return $test->cpPlan('Monthly plain', PlanBillingType::RecurringOnly, '0.00', '25.00', [Feature::Loyalty, Feature::CloudSync]);
}

function oscStore(Company $company): ?OnlineStore
{
    return OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->first();
}

test('to a plan with the online store: the preview says it is switched on, asks the template and shows the new amount', function () {
    $company = oscBusiness($this);
    $to = oscStorePlan($this);

    $preview = $this->previewPlan($company, $to)->assertOk()->json();
    $lines = implode(' ', $preview['onlineStore']['lines']);

    expect($preview['blocked'])->toBeNull()
        ->and($preview['onlineStore']['change'])->toBe('on')
        ->and($preview['onlineStore']['needsTemplate'])->toBeTrue()
        ->and(collect($preview['onlineStore']['templates'])->pluck('value')->all())->toBe(['freshMarket', 'butcher'])
        ->and($lines)->toContain('Switched on: Monthly + website includes the online store.')
        ->toContain('£20.00 a month before VAT, once per business whatever its branches and tills')
        ->toContain('Included in the new amount above: Online store £24.00 per month with VAT.')
        ->and($preview['recurring']['lines'][0])->toBe('Changes from £60.00 to £84.00 per month (£25.00 per till + VAT) from the next period (starting 1 Nov 2026). Time already paid is not charged again.')
        ->and(oscStore($company))->toBeNull();

    // The confirm needs the template.
    $this->changePlan($company, $to)->assertSessionHasErrors(['store_template' => 'Choose the online store’s template: Monthly + website includes the online store.']);
    expect($company->refresh()->plan_id)->not->toBe($to->id);

    $subscription = $this->gc->lastSubscription();
    $this->changePlan($company, $to, ['store_template' => 'butcher'])->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'The online store add-on is switched on with the plan'));

    $store = oscStore($company);
    expect($store->addon_on)->toBeTrue()->and($store->template)->toBe(StoreTemplate::Butcher)->and($store->is_live)->toBeFalse()
        ->and($store->monthly_price)->toBeNull()
        ->and(OnlineStoreSwitch::availableFor($company->refresh()))->toBeTrue()
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'])->toBe('84.00')
        ->and($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(8400)
        ->and(AuditLog::query()->where('action', 'online_store.addon_updated')->sole()->meta)->toEqual(['plan' => $to->code]);
});

test('to a plan without the online store: the preview says it is switched off; the store goes offline and the line stops', function () {
    $company = oscBusiness($this);
    $from = oscStorePlan($this, '15.00');
    $this->changePlan($company, $from, ['store_template' => 'freshMarket'])->assertSessionHasNoErrors();
    oscStore($company)->forceFill(['is_live' => true, 'taking_orders' => true])->save();
    $subscription = $this->gc->lastSubscription();
    expect($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(7800);

    $to = oscPlainPlan($this);
    $preview = $this->previewPlan($company, $to)->assertOk()->json();

    expect($preview['onlineStore']['change'])->toBe('off')
        ->and($preview['onlineStore']['needsTemplate'])->toBeFalse()
        ->and($preview['onlineStore']['lines'][0])->toBe('Switched off: Monthly plain does not include the online store. The store goes offline and its “Online store” line (£15.00 a month before VAT) stops from the next invoice. Its settings are kept.')
        ->and($preview['recurring']['lines'][0])->toContain('Changes from £78.00 to £60.00 per month');

    $this->changePlan($company, $to)->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'The online store add-on is switched off'));

    $store = oscStore($company);
    expect($store->addon_on)->toBeFalse()
        ->and($store->template)->toBe(StoreTemplate::FreshMarket) // settings kept
        ->and(OnlineStoreSwitch::availableFor($company->refresh()))->toBeFalse()
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['onlineStore'])->toBeNull()
        ->and($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(6000);

    // Back to a plan with it: its store comes back as it was, no template asked.
    $back = $this->previewPlan($company, $from)->assertOk()->json('onlineStore');
    expect($back['needsTemplate'])->toBeFalse()->and($back['lines'][0])->toContain('Its store /khan-mini-mart is switched back on as it was set up (Fresh Market).');
    $this->changePlan($company, $from)->assertSessionHasNoErrors();
    expect(oscStore($company)->addon_on)->toBeTrue()->and(oscStore($company)->template)->toBe(StoreTemplate::FreshMarket);
});

test('an add-on switched on by hand: the preview says so; a plan without the store switches it off, one with it keeps its own price', function () {
    $company = oscBusiness($this);
    app(SetOnlineStoreAddOn::class)->handle($company, new OnlineStoreAddOnInput(true, '0.00', StoreTemplate::FreshMarket));

    $off = $this->previewPlan($company, oscPlainPlan($this))->assertOk()->json('onlineStore');
    expect($off['change'])->toBe('off')
        ->and($off['lines'][1])->toBe('The add-on was switched on by hand on the Billing tab. Changing plan follows the new plan; switch it on again on the Billing tab afterwards if this business should keep it.');

    $to = oscStorePlan($this, '15.00');
    $stays = $this->previewPlan($company, $to)->assertOk()->json('onlineStore');
    expect($stays['change'])->toBe('stays')
        ->and($stays['lines'][0])->toBe('Stays on: Monthly + website includes the online store, so the add-on switched on by hand now follows the plan.')
        ->and($stays['lines'][1])->toContain('£0.00 a month before VAT (the business’s own price)');

    $this->changePlan($company, $to)->assertSessionHasNoErrors();
    expect(oscStore($company)->addon_on)->toBeTrue()->and(oscStore($company)->monthly_price)->toBe('0.00')
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['onlineStore'])->toBe('0.00')
        ->and(AuditLog::query()->where('action', 'online_store.addon_updated')->count())->toBe(1); // nothing changed on it

    // The Billing tab card keeps working as the exception path (price override).
    $this->actingAs($this->admin(), 'admin')->put(route('admin.tenants.online-store.update', $company), ['on' => true, 'monthly_price' => '9'])->assertSessionHasNoErrors();
    expect(SubscriptionAmount::for($company, $this->billingAccountOf($company))['onlineStore'])->toBe('9.00');
});

test('a plan change that leaves the add-on off shows nothing about it and changes nothing (as before)', function () {
    $company = oscBusiness($this);
    $to = oscPlainPlan($this);

    expect($this->previewPlan($company, $to)->assertOk()->json('onlineStore'))->toBeNull();
    $this->changePlan($company, $to)->assertSessionHasNoErrors();
    expect(oscStore($company))->toBeNull()->and(AuditLog::query()->where('action', 'like', 'online_store.%')->count())->toBe(0);
});

test('to a setup-only plan: the add-on goes off with the recurring fee', function () {
    $company = oscBusiness($this);
    $this->changePlan($company, oscStorePlan($this), ['store_template' => 'freshMarket'])->assertSessionHasNoErrors();
    $setupOnly = $this->cpPlan('Setup only', PlanBillingType::SetupOnly, '500.00', '0.00');

    expect($this->previewPlan($company, $setupOnly)->assertOk()->json('onlineStore.change'))->toBe('off');
    $this->changePlan($company, $setupOnly, ['setup_fee' => '0'])->assertSessionHasNoErrors();
    expect(oscStore($company)->addon_on)->toBeFalse();
});

test('with online stores switched off on the server, a plan with the store is blocked in the preview and refused', function () {
    $company = oscBusiness($this);
    $to = oscStorePlan($this);
    config(['online_store.enabled' => false]);
    $message = 'Monthly + website includes the online store, but online stores are switched off on this server (ONLINE_STORE). Choose another plan, or switch online stores on first.';

    $this->previewPlan($company, $to)->assertOk()->assertJsonPath('blocked', $message);
    $this->changePlan($company, $to, ['store_template' => 'butcher'])->assertSessionHasErrors(['plan_id' => $message]);
    expect(oscStore($company))->toBeNull()->and($company->refresh()->plan_id)->not->toBe($to->id);
});

test('the Change plan options show which plans include the online store and whether the business has a store', function () {
    $company = oscBusiness($this);
    $to = oscStorePlan($this, '12.00');

    $this->actingAs($this->admin(), 'admin')->get(route('admin.tenants.show', $company))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('billing.planChange.hasOnlineStore', false)
            ->where('billing.planChange.plans', fn ($plans) => collect($plans)->firstWhere('id', $to->id)['onlineStore'] === '£12.00 per month'
                && collect($plans)->firstWhere('id', $this->standardPlan()->id)['onlineStore'] === null));
});
