<?php

use App\Domain\OnlineStore\Actions\SaveStoreLegal;
use App\Domain\OnlineStore\Actions\SetStoreLive;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
| OS-11, audit OSA-2: the trader details UK law asks an online shop to show, the generated contact, privacy, terms and
| cookies pages, the footer and checkout links, and going live only with the details saved.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->f->company->forceFill(['legal_name' => 'Kirkgate Convenience Ltd', 'address' => '12 Kirkgate, Leeds, LS2 7DJ', 'town' => 'Leeds',
        'postcode' => 'LS2 7DJ', 'company_number' => '01234567', 'vat_number' => 'GB123456789', 'email' => 'hello@kirkgate.test', 'phone' => '0113 496 0000'])->save();
    $this->owner = C::member($this->f->company, CompanyRole::Owner);
    $this->url = fn (string $name) => route($name, ['slug' => 'kirkgate'], false);
    $this->valid = ['trading_name' => 'Kirkgate Market', 'legal_name' => 'Kirkgate Convenience Ltd', 'legal_address' => '12 Kirkgate, Leeds, LS2 7DJ',
        'company_number' => '01234567', 'vat_number' => 'GB 123 4567 89', 'contact_email' => 'shop@kirkgate.test', 'contact_phone' => '0113 496 0000'];
    $this->error = function (callable $call): array {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
});

test('the Legal & contact tab starts from the business record, without repeating the town and postcode', function () {
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['trading_name' => null, 'legal_name' => null, 'legal_address' => null, 'contact_email' => null]);

    $this->actingAs($this->owner)->get('/app/online-store/legal')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/online-store/legal')
        ->where('details.trading_name', 'Kirkgate Convenience')
        ->where('details.legal_name', 'Kirkgate Convenience Ltd')
        ->where('details.legal_address', '12 Kirkgate, Leeds, LS2 7DJ')
        ->where('details.vat_number', 'GB123456789')
        ->where('details.contact_email', 'hello@kirkgate.test')
        ->where('saved', false)
        ->where('missing', ['trading name', 'legal name', 'business address', 'contact email'])
        ->has('pages', 4));
});

test('saving: required details, tidy company and VAT numbers, extra text per page, audited; nothing changed writes nothing', function () {
    $this->actingAs($this->owner)->put('/app/online-store/legal', [...$this->valid, 'extra' => ['terms' => "Our own returns:\r\n30 days.", 'cookies' => '  ']])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    $store = OnlineStore::withoutCompanyScope()->find($this->f->store->id);

    expect($store->trading_name)->toBe('Kirkgate Market')
        ->and($store->vat_number)->toBe('GB123456789')
        ->and($store->legal_extra)->toBe(['terms' => "Our own returns:\n30 days."])
        ->and(AuditLog::query()->where('action', 'online_store.legal_updated')->count())->toBe(1);

    app(SaveStoreLegal::class)->handle($this->f->company, [...$this->valid, 'extra' => ['terms' => "Our own returns:\n30 days."]]);
    expect(AuditLog::query()->where('action', 'online_store.legal_updated')->count())->toBe(1);

    $errors = ($this->error)(fn () => app(SaveStoreLegal::class)->handle($this->f->company, [...$this->valid, 'trading_name' => '', 'legal_address' => ' ',
        'company_number' => '123', 'vat_number' => 'FR123', 'contact_email' => 'nope', 'contact_phone' => 'call me', 'extra' => ['blog' => 'x']]));
    expect(array_keys($errors))->toEqualCanonicalizing(['trading_name', 'legal_address', 'company_number', 'vat_number', 'contact_email', 'contact_phone', 'extra']);
});

test('only users who manage every shop save; staff and one-shop users cannot', function () {
    $this->actingAs(C::member($this->f->company, CompanyRole::Staff))->get('/app/online-store/legal')->assertForbidden();
    $this->actingAs(C::member($this->f->company, CompanyRole::Staff))->put('/app/online-store/legal', $this->valid)->assertForbidden();
    $oneShop = C::member($this->f->company, CompanyRole::Manager, $this->f->leeds->id);
    $this->actingAs($oneShop)->get('/app/online-store/legal')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    $this->actingAs($oneShop)->put('/app/online-store/legal', $this->valid)->assertForbidden();
    auth('web')->logout();
    $this->put('/app/online-store/legal', $this->valid)->assertRedirect('/login');
});

test('going live needs the trader details; the builder checklist says which', function () {
    app(SetStoreLive::class)->handle($this->f->company, false);
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['legal_name' => null, 'contact_email' => null]);

    expect(($this->error)(fn () => app(SetStoreLive::class)->handle($this->f->company, true)))
        ->toBe(['store' => ['Add your legal name and contact email on the Legal & contact tab before you publish the store.']]);
    $this->actingAs($this->owner)->get('/app/online-store')->assertInertia(fn (Assert $page) => $page
        ->where('checklist.legalMissing', ['legal name', 'contact email']));

    app(SaveStoreLegal::class)->handle($this->f->company, $this->valid);
    expect(app(SetStoreLive::class)->handle($this->f->company, true)->is_live)->toBeTrue();
});

test('the public legal pages show the trader details, what the store offers and the shop\'s own text', function () {
    app(SaveStoreLegal::class)->handle($this->f->company, [...$this->valid, 'extra' => ['terms' => 'Bread is baked daily.']]);

    $this->get(($this->url)('store.terms'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('store/legal')
        ->where('page', 'terms')
        ->where('title', 'Terms of sale')
        ->where('extra', 'Bread is baked daily.')
        ->where('sections.0.paragraphs', fn ($p) => collect($p)->contains('These terms apply to orders placed in this online store. The shop is run by Kirkgate Convenience Ltd, trading as Kirkgate Market.')
            && collect($p)->contains('Company number: 01234567.') && collect($p)->contains('VAT number: GB123456789.'))
        ->where('sections', fn ($s) => collect($s)->pluck('heading')->contains('Click & collect') && ! collect($s)->pluck('heading')->contains('Local delivery')
            && str_contains(json_encode($s), 'age-restricted') && str_contains(json_encode($s), '14 days'))
        ->where('store.trader.tradingName', 'Kirkgate Market')
        ->where('store.urls.privacy', ($this->url)('store.privacy')));

    $this->get(($this->url)('store.privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sections', fn ($s) => str_contains(json_encode($s), 'Information Commissioner') && str_contains(json_encode($s), '24 months')));
    $this->get(($this->url)('store.cookies'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sections.0.heading', 'Only strictly necessary cookies')
        ->where('sections', fn ($s) => str_contains(json_encode($s), 'XSRF-TOKEN') && ! str_contains(json_encode($s), 'analytics cookies:')));
    $this->get(($this->url)('store.contact'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sections.0.paragraphs', ['Email: shop@kirkgate.test', 'Phone: 0113 496 0000',
            'About an order? Have your order number ready (it starts with W-, and is in your order email).']));
    $this->get(($this->url)('store.home'))->assertInertia(fn (Assert $page) => $page
        ->where('store.trader.companyNumber', '01234567')
        ->where('store.urls.terms', ($this->url)('store.terms')));
});

test('each store\'s pages show its own trader, never another business\'s', function () {
    app(SaveStoreLegal::class)->handle($this->f->company, $this->valid);
    $other = OnlineStoreFixtures::make('Harehills Halal', 'harehills');
    app(SaveStoreLegal::class)->handle($other->company, [...$this->valid, 'trading_name' => 'Harehills Halal', 'legal_name' => 'HH Foods Ltd', 'legal_address' => '3 Harehills Lane, Leeds, LS8 4HX',
        'company_number' => '07654321', 'contact_email' => 'hh@harehills.test']);

    $this->get(route('store.terms', ['slug' => 'harehills'], false))->assertInertia(fn (Assert $page) => $page
        ->where('store.trader.legalName', 'HH Foods Ltd')
        ->where('sections', fn ($s) => ! str_contains(json_encode($s), 'Kirkgate') && str_contains(json_encode($s), '07654321')));
});
