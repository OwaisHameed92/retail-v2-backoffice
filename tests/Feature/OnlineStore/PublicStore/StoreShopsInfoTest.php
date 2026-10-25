<?php

use App\Domain\OnlineStore\Models\OnlineStore;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
| OS-11 (owner's review of the live store): the "Where to collect" cards and the footer get a clean address (no town
| or postcode twice), the shop name in normal case, today's hours and the shop's delivery when it delivers.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));   // a Monday
    $this->f = OnlineStoreFixtures::make();
    $this->f->leeds->forceFill(['name' => 'BIRMINGHAM', 'address' => '1 High St, Birmingham, B23 5GF', 'town' => 'Birmingham', 'postcode' => 'B23 5GF'])->save();
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['sections' => json_encode([['key' => 'shops', 'enabled' => true]])]);
});

test('a shop shows its name in normal case, a clean address, today and open now', function () {
    $this->get(route('store.home', ['slug' => 'kirkgate'], false))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shops.0.name', 'Birmingham')
        ->where('shops.0.address', '1 High St, Birmingham, B23 5GF')
        ->where('shops.0.today', 'Monday')
        ->where('shops.0.openNow.open', true)
        ->where('shops.0.delivery', null)
        ->where('store.shop.address', '1 High St, Birmingham, B23 5GF'));
});

test('a shop that delivers shows its areas and fees', function () {
    $this->f->shop->forceFill(['delivery_enabled' => true, 'delivery_areas' => ['B23', 'B24 9'], 'delivery_fee' => '2.50',
        'delivery_free_over' => '30.00', 'delivery_minimum' => null])->save();

    $this->get(route('store.home', ['slug' => 'kirkgate'], false))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shops.0.delivery.areas', ['B23', 'B24 9'])->where('shops.0.delivery.fee', '2.50')
        ->where('shops.0.delivery.freeOver', '30.00')->where('shops.0.delivery.minimum', null)
        // OS-12: the delivery hours come with it (none saved here).
        ->where('shops.0.delivery.hours.0', ['day' => 'Monday', 'hours' => null]));
});
