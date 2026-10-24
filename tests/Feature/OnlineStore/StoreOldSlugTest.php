<?php

use App\Domain\OnlineStore\Actions\SaveOnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreOldSlug;
use App\Domain\OnlineStore\Support\StoreOldSlugs;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
| OS-11, audit OSA-6: a changed store address keeps the old one redirecting (301) to the new one for 90 days, and no
| other business can take it in that time.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->rename = fn (OnlineStoreFixtures $f, string $slug) => app(SaveOnlineStore::class)->handle($f->company, ['slug' => $slug]);
});

test('the old address answers 301 to the same page at the new one, query kept; pages only', function () {
    $beans = $this->f->product('Beans');
    ($this->rename)($this->f, 'kirkgate-market');

    $this->get('/store/kirkgate')->assertStatus(301)->assertRedirect(url('/store/kirkgate-market'));
    $this->get("/store/kirkgate/product/{$beans}?ref=qr")->assertStatus(301)->assertRedirect(url("/store/kirkgate-market/product/{$beans}?ref=qr"));
    $this->get('/store/kirkgate-market')->assertOk();
    $this->post('/store/kirkgate/basket', ['product_id' => $beans, 'quantity' => '1'])->assertNotFound();

    expect(OnlineStoreOldSlug::withoutCompanyScope()->where('slug', 'kirkgate')->value('expires_at')?->toDateString())->toBe('2027-01-31');
});

test('another business cannot take the old address for 90 days; afterwards it is let go', function () {
    ($this->rename)($this->f, 'kirkgate-market');
    $other = OnlineStoreFixtures::make('Harehills Halal', 'harehills');

    expect(fn () => ($this->rename)($other, 'kirkgate'))->toThrow(ValidationException::class, 'That address is already taken.');

    $this->travelTo(CarbonImmutable::parse('2027-02-01 08:00:00', 'UTC'));
    $this->get('/store/kirkgate')->assertNotFound();
    expect(StoreOldSlugs::prune())->toBe(1);

    ($this->rename)($other, 'kirkgate');
    $this->get('/store/kirkgate')->assertOk();
});

test('taking the old address back makes it current again; the store\'s other old address still redirects', function () {
    ($this->rename)($this->f, 'kirkgate-market');
    ($this->rename)($this->f, 'kirkgate');

    $this->get('/store/kirkgate')->assertOk();
    $this->get('/store/kirkgate-market')->assertStatus(301)->assertRedirect(url('/store/kirkgate'));
    expect(OnlineStoreOldSlug::withoutCompanyScope()->pluck('slug')->all())->toBe(['kirkgate-market']);
});

test('no redirect while the store itself is not open (offline or switched off)', function () {
    ($this->rename)($this->f, 'kirkgate-market');
    $this->f->store->refresh()->forceFill(['is_live' => false])->save();

    $this->get('/store/kirkgate')->assertNotFound();
});
