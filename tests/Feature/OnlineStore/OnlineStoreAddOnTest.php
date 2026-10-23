<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\TenantBilling;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\PurgeCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Tenants\TenantTestHelpers;

/* OS-6: the admin's online store add-on switch (price, template), what makes a store usable, and the purge of its files. */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->fakeGoCardless();
    config(['online_store.enabled' => true]);
    $this->company = $this->payingTenant();

    foreach ($this->licencesOf($this->company) as $licence) {
        $licence->forceFill(['features' => [Feature::CloudSync->value]])->save();
    }

    $this->url = route('admin.tenants.online-store.update', $this->company);
    $this->store = fn (?Company $company = null) => OnlineStore::withoutCompanyScope()->where('company_id', ($company ?? $this->company)->id)->first();
});

test('switching on makes the store with the chosen template, not live, at the default price; audited', function () {
    $this->actingAs($this->admin(), 'admin')->put($this->url, ['on' => true, 'monthly_price' => '', 'template' => 'butcher'])
        ->assertRedirect()->assertSessionHas('success');

    $store = ($this->store)();
    expect($store->addon_on)->toBeTrue()
        ->and($store->addon_on_at)->not->toBeNull()
        ->and($store->template)->toBe(StoreTemplate::Butcher)
        ->and($store->slug)->toBe('khan-mini-mart')
        ->and($store->is_live)->toBeFalse()->and($store->taking_orders)->toBeFalse()
        ->and($store->monthly_price)->toBeNull()
        ->and(AuditLog::query()->where('action', 'online_store.created')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'online_store.addon_updated')->sole()->after)->toBe(['addon_on' => true, 'monthly_price' => null, 'template' => 'butcher']);
});

test('the price and template can change later (audited); off keeps the store row; the slug avoids one taken', function () {
    $other = Company::factory()->create(['name' => 'Khan Mini Mart']);
    $taken = new OnlineStore(['slug' => 'khan-mini-mart', 'template' => StoreTemplate::FreshMarket]);
    $taken->company_id = $other->id;
    $taken->save();

    $admin = $this->admin(AdminRole::Accounts);
    $this->actingAs($admin, 'admin')->put($this->url, ['on' => true, 'template' => 'freshMarket'])->assertSessionHasNoErrors();
    expect(($this->store)()->slug)->toBe('khan-mini-mart-2');

    $this->actingAs($admin, 'admin')->put($this->url, ['on' => true, 'monthly_price' => '£12.50', 'template' => 'butcher'])->assertSessionHasNoErrors();
    expect(($this->store)()->monthly_price)->toBe('12.50')->and(($this->store)()->template)->toBe(StoreTemplate::Butcher);

    $this->actingAs($admin, 'admin')->put($this->url, ['on' => false, 'monthly_price' => '12.50'])->assertSessionHasNoErrors();
    expect(($this->store)()->addon_on)->toBeFalse()->and(($this->store)()->template)->toBe(StoreTemplate::Butcher)
        ->and(AuditLog::query()->where('action', 'online_store.addon_updated')->count())->toBe(3);
});

test('refused: no template, the pharmacy template, a negative price, no cloud sync, the server switch off', function () {
    $admin = $this->admin();

    $this->actingAs($admin, 'admin')->put($this->url, ['on' => true])->assertSessionHasErrors(['template' => 'Choose the store’s template.']);
    $this->actingAs($admin, 'admin')->put($this->url, ['on' => true, 'template' => 'pharmacy'])->assertSessionHasErrors('template');
    $this->actingAs($admin, 'admin')->put($this->url, ['on' => true, 'template' => 'butcher', 'monthly_price' => '-1'])->assertSessionHasErrors('monthly_price');
    $this->actingAs($admin, 'admin')->put($this->url, ['on' => false])->assertSessionHasErrors('on'); // nothing to switch off

    config(['online_store.enabled' => false]);
    $this->actingAs($admin, 'admin')->put($this->url, ['on' => true, 'template' => 'butcher'])->assertSessionHasErrors('on');
    config(['online_store.enabled' => true]);

    foreach ($this->licencesOf($this->company) as $licence) {
        $licence->forceFill(['features' => [Feature::Loyalty->value]])->save();
    }
    $this->actingAs($admin, 'admin')->put($this->url, ['on' => true, 'template' => 'butcher'])->assertSessionHasErrors('on');

    expect(($this->store)())->toBeNull();
});

test('admin only, billing.manage: guests sign in, support and sales get 403, customers never reach it; 404 on Pakistan', function () {
    $this->put($this->url, ['on' => true, 'template' => 'butcher'])->assertRedirect(route('admin.login'));

    foreach ([AdminRole::Support, AdminRole::Sales] as $role) {
        $this->actingAs($this->admin($role), 'admin')->put($this->url, ['on' => true, 'template' => 'butcher'])->assertForbidden();
    }

    $this->app['auth']->forgetGuards();
    $this->actingAs($this->ownerOf($this->company))->put($this->url, ['on' => true, 'template' => 'butcher'])->assertRedirect(route('admin.login'));
    expect(($this->store)())->toBeNull();

    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
    $this->actingAs($this->admin(), 'admin')->put($this->url, ['on' => true, 'template' => 'butcher'])->assertNotFound();
    expect(TenantBilling::for($this->company, true)['onlineStore'])->toBeNull();
});

test('the Billing tab card: on, price, template, the invoice line and why a store cannot be used', function () {
    $this->actingAs($this->admin(), 'admin')->put($this->url, ['on' => true, 'monthly_price' => '15', 'template' => 'butcher']);
    $card = TenantBilling::for($this->company, true)['onlineStore'];

    expect($card)->toMatchArray([
        'on' => true, 'monthlyPrice' => '15.00', 'defaultPrice' => '20.00', 'priceLabel' => '£15.00', 'custom' => true,
        'cycleNet' => '£15.00', 'billed' => true, 'monthlyPlan' => true, 'template' => 'butcher', 'templateLabel' => 'Butcher\'s Block',
        'slug' => 'khan-mini-mart', 'live' => false, 'cloudSync' => true, 'available' => true,
    ])->and(collect($card['templates'])->pluck('value')->all())->toBe(['freshMarket', 'butcher']);
});

test('the portal store pages, menu item and public store need the add-on: off → 404', function () {
    $f = OnlineStoreFixtures::make();
    $owner = $this->addMember($f->company, CompanyRole::Owner);

    $this->actingAs($owner)->get('/app/online-store')->assertOk();
    $this->actingAs($owner)->get('/app/online-orders')->assertOk();
    expect(app(FindStoreBySlug::class)->handle('kirkgate'))->not->toBeNull();

    OnlineStore::withoutCompanyScope()->whereKey($f->store->id)->update(['addon_on' => false]);

    $this->actingAs($owner)->get('/app/online-store')->assertNotFound();
    $this->actingAs($owner)->get('/app/online-orders')->assertNotFound();
    $this->actingAs($owner)->get('/app')->assertInertia(fn (Assert $page) => $page->where('onlineOrdersNew', null)
        ->where('abilities', fn ($abilities) => ! collect($abilities)->contains('store.manage')));
    expect(app(FindStoreBySlug::class)->handle('kirkgate'))->toBeNull();
});

test('purging a business removes its online store pictures, and nobody else\'s', function () {
    Storage::fake('public');
    Storage::fake('local');
    $other = $this->payingTenant('Stoney Mini Mart', 1, 'STN');
    Storage::disk('public')->put("online-store/{$this->company->id}/logo/a.png", 'x');
    Storage::disk('public')->put("online-store/{$this->company->id}/products/b.jpg", 'x');
    Storage::disk('public')->put("online-store/{$other->id}/logo/c.png", 'x');

    expect(app(PurgeCompany::class)->plan($this->company)['files'] ?? 0)->toBe(2);
    app(PurgeCompany::class)->handle($this->company, force: true);

    expect(Storage::disk('public')->exists("online-store/{$this->company->id}"))->toBeFalse()
        ->and(Storage::disk('public')->allFiles("online-store/{$this->company->id}"))->toBe([])
        ->and(Storage::disk('public')->exists("online-store/{$other->id}/logo/c.png"))->toBeTrue();
});
