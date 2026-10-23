<?php

use App\Domain\Calendar\Models\ShopOpeningHour;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/*
 * OS-3: the online store's collection shops page (SaveStoreShop): per open shop, collection on or off, hours per
 * weekday, ready time, slot length and orders per slot.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->f = OnlineStoreFixtures::make();
    $this->company = $this->f->company;
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $this->rows = fn (?Company $company = null) => OnlineStoreShop::withoutCompanyScope()->where('company_id', ($company ?? $this->company)->id)->get()->keyBy('branch_id');
});

test('every open shop is listed with its settings; a new shop starts from its Calendar hours', function () {
    Branch::factory()->forCompany($this->company)->create(['code' => 'OLD', 'name' => 'Closed shop', 'is_active' => false]);
    foreach (range(1, 7) as $day) {
        ShopOpeningHour::query()->forceCreate(['id' => Ulid::new(), 'company_id' => $this->company->id, 'branch_id' => $this->f->bradford->id, 'weekday' => $day,
            'is_closed' => $day === 7, 'opens_at' => $day === 7 ? null : '07:30', 'closes_at' => $day === 6 ? '01:00' : ($day === 7 ? null : '21:00')]);
    }

    $this->actingAs($this->owner)->get('/app/online-store/shops')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('app/online-store/shops')
        ->where('hasStore', true)
        ->has('shops', 2)
        ->where('shops.0.name', 'Bradford')
        ->where('shops.0.saved', false)
        ->where('shops.0.hoursSource', 'calendar')
        ->where('shops.0.days.0', ['day' => 'monday', 'open' => '07:30', 'close' => '21:00'])
        ->where('shops.0.days.5', ['day' => 'saturday', 'open' => '07:30', 'close' => '23:59'])
        ->where('shops.0.days.6', ['day' => 'sunday', 'open' => null, 'close' => null])
        ->where('shops.1.name', 'Leeds')
        ->where('shops.1.saved', true)
        ->where('shops.1.enabled', true)
        ->where('shops.1.ordersPerSlot', 2)
        ->where('shops.1.days.2', ['day' => 'wednesday', 'open' => '08:00', 'close' => '20:00'])
        ->where('slotOptions', [10, 15, 20, 30, 45, 60, 90, 120]));
});

test('adding and changing a collection shop', function () {
    $input = OnlineStoreFixtures::shopInput(['opening_hours' => ['monday' => ['open' => '09:00', 'close' => '17:00'], 'sunday' => null], 'orders_per_slot' => 5]);

    $this->actingAs($this->owner)->put("/app/online-store/shops/{$this->f->bradford->id}", $input)
        ->assertRedirect()->assertSessionHas('success', 'Bradford saved. Customers can collect there.');
    $row = ($this->rows)()->get($this->f->bradford->id);
    expect($row->hoursOn('monday'))->toBe(['open' => '09:00', 'close' => '17:00'])->and($row->hoursOn('tuesday'))->toBeNull()
        ->and($row->orders_per_slot)->toBe(5)->and($row->enabled)->toBeTrue();

    $this->actingAs($this->owner)->put("/app/online-store/shops/{$this->f->bradford->id}", [...$input, 'enabled' => false])
        ->assertSessionHas('success', 'Bradford saved. Customers cannot collect there.');
    expect(($this->rows)()->get($this->f->bradford->id)->enabled)->toBeFalse()->and(($this->rows)())->toHaveCount(2);
});

test('collection settings are checked (422): :dataset', function (array $overrides, string $field) {
    $this->actingAs($this->owner)->putJson("/app/online-store/shops/{$this->f->leeds->id}", OnlineStoreFixtures::shopInput($overrides))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(($this->rows)()->get($this->f->leeds->id)->orders_per_slot)->toBe(2);
})->with([
    'closing before opening' => [['opening_hours' => ['monday' => ['open' => '18:00', 'close' => '09:00']]], 'opening_hours.monday.close'],
    'not a time' => [['opening_hours' => ['monday' => ['open' => '9am', 'close' => '17:00']]], 'opening_hours.monday.open'],
    'slot not in 5 minutes' => [['slot_minutes' => 7], 'slot_minutes'],
    'no orders per slot' => [['orders_per_slot' => 0], 'orders_per_slot'],
    'too many per slot' => [['orders_per_slot' => 101], 'orders_per_slot'],
    'negative ready time' => [['prep_minutes' => -5], 'prep_minutes'],
    'missing switch' => [['enabled' => null], 'enabled'],
]);

test('a business without a store must set it up first', function () {
    $other = Company::factory()->create();
    $shop = Branch::factory()->forCompany($other)->create();
    $owner = F::member($other, CompanyRole::Owner);

    $this->actingAs($owner)->get('/app/online-store/shops')->assertInertia(fn (Assert $p) => $p->where('hasStore', false)->has('shops', 1));
    $this->actingAs($owner)->putJson("/app/online-store/shops/{$shop->id}", OnlineStoreFixtures::shopInput())
        ->assertUnprocessable()->assertJsonPath('errors.store.0', 'Set up the online store first.');
});

test('a one-shop manager sees and changes only their own shop', function () {
    $oneShop = F::member($this->company, CompanyRole::Manager, $this->f->bradford);

    $this->actingAs($oneShop)->get('/app/online-store/shops')->assertInertia(fn (Assert $p) => $p->has('shops', 1)->where('shops.0.name', 'Bradford'));
    $this->actingAs($oneShop)->putJson("/app/online-store/shops/{$this->f->leeds->id}", OnlineStoreFixtures::shopInput(['orders_per_slot' => 9]))->assertForbidden();
    $this->actingAs($oneShop)->put("/app/online-store/shops/{$this->f->bradford->id}", OnlineStoreFixtures::shopInput())->assertSessionHas('success');

    expect(($this->rows)()->get($this->f->leeds->id)->orders_per_slot)->toBe(2)->and(($this->rows)()->has($this->f->bradford->id))->toBeTrue();
});

test('company A can never read or change company B\'s shops; a B shop id is refused', function () {
    $b = OnlineStoreFixtures::make('Other Shop', 'other-shop');

    $this->actingAs($this->owner)->get('/app/online-store/shops')->assertInertia(fn (Assert $p) => $p->has('shops', 2)
        ->where('shops', fn ($shops) => collect($shops)->pluck('id')->intersect([$b->leeds->id, $b->bradford->id])->isEmpty()));

    foreach ([$b->leeds->id, $b->bradford->id] as $bShop) {
        $this->actingAs($this->owner)->putJson("/app/online-store/shops/{$bShop}", OnlineStoreFixtures::shopInput(['orders_per_slot' => 50]))
            ->assertUnprocessable()->assertJsonPath('errors.branch_id.0', 'Choose one of your open shops.');
    }

    expect(($this->rows)($b->company))->toHaveCount(1)->and(($this->rows)($b->company)->first()->orders_per_slot)->toBe(2)
        ->and(($this->rows)())->toHaveCount(1);
});
