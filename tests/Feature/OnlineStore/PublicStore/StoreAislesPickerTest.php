<?php

use App\Domain\OnlineStore\Actions\SaveStoreAisles;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
 * OS-12: one choice of departments. The builder's "Aisles" drive the header, the "Shop by aisle" tiles and the
 * department rows (their section still switched on). The old "Department rows" picker is gone; a store that never
 * saved its aisles but picked home-row departments (`home_departments`) gets those as its aisles, in that order, the
 * others hidden (read-time fallback, nothing rewritten). OS-11's rules stay: no "no department", Title Case,
 * categories when departments are few, the shop's names, photos and order.
 */

beforeEach(function () {
    Storage::fake('public');
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->depts = [];

    foreach (['FRESH FRUIT', 'Dairy', 'Bakery', 'Frozen', 'Drinks', 'Household', 'Snacks'] as $i => $name) {
        $this->depts[$name] = OnlineStoreFixtures::department($this->f->company, $name, $i + 2);
        $this->f->product("{$name} item", ['department_id' => $this->depts[$name]]);
    }

    $this->none = OnlineStoreFixtures::department($this->f->company, 'NO DEPARTMENT', 0);
    $this->f->product('Loose', ['department_id' => $this->none]);
    $this->f->product('Beans');   // Grocery, the fixture's department
    $this->set = fn (array $columns) => OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update($columns);
    ($this->set)(['sections' => json_encode(array_map(fn ($k) => ['key' => $k, 'enabled' => true], ['departments', 'departmentRows']))]);
    // The owner's live case: five departments picked with the old "Department rows" picker; aisles never saved.
    $this->picked = [$this->depts['Drinks'], $this->depts['FRESH FRUIT'], $this->none, $this->depts['Snacks'], $this->depts['Dairy'], $this->depts['Bakery']];
    $this->home = fn () => $this->get(($this->url)('store.home'))->assertOk()->viewData('page')['props'];
});

test('the owner\'s case: the five picked departments are the aisles, tiles and rows, in the order picked', function () {
    ($this->set)(['home_departments' => json_encode($this->picked), 'aisles' => null, 'aisle_source' => null]);

    $props = ($this->home)();
    $order = ['Drinks', 'Fresh Fruit', 'Snacks', 'Dairy', 'Bakery'];   // "no department" never; Title Case

    expect(collect($props['store']['departments'])->pluck('name')->all())->toBe($order)
        ->and(collect($props['departmentTiles'])->pluck('name')->all())->toBe($order)
        ->and(collect($props['departmentRows'])->pluck('name')->all())->toBe($order)
        ->and($props['departmentRows'][0]['products'][0]['name'])->toBe('Drinks item');

    // The builder shows the same: the picked ones first and shown, the rest hidden, ready to change.
    $this->actingAs(C::member($this->f->company, CompanyRole::Owner))->get('/app/online-store')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('aisles', fn ($aisles) => collect($aisles)->reject(fn ($a) => $a['hidden'])->pluck('name')->all() === $order
                && collect($aisles)->filter(fn ($a) => $a['hidden'])->pluck('name')->sort()->values()->all() === ['Frozen', 'Grocery', 'Household'])
            ->missing('store.homeDepartments')
            ->missing('homeDepartmentsMax'));

    // Nothing was rewritten.
    expect(OnlineStore::withoutCompanyScope()->find($this->f->store->id)->aisles)->toBeNull();
});

test('department rows follow the aisles: the first six shown, their section still needed', function () {
    $props = ($this->home)();

    expect(collect($props['store']['departments'])->pluck('name')->all())->toBe(['Grocery', 'Fresh Fruit', 'Dairy', 'Bakery', 'Frozen', 'Drinks', 'Household', 'Snacks'])
        ->and(collect($props['departmentRows'])->pluck('name')->all())->toBe(['Grocery', 'Fresh Fruit', 'Dairy', 'Bakery', 'Frozen', 'Drinks']);

    ($this->set)(['sections' => json_encode([['key' => 'departments', 'enabled' => true], ['key' => 'departmentRows', 'enabled' => false]])]);
    expect(($this->home)()['departmentRows'])->toBe([]);
});

test('saved aisles win over the old picks: order, names and hidden ones', function () {
    ($this->set)(['home_departments' => json_encode($this->picked)]);
    app(SaveStoreAisles::class)->handle($this->f->company, ['source' => 'departments', 'aisles' => [
        ['id' => $this->depts['Bakery'], 'name' => 'Fresh bread', 'hidden' => false],
        ['id' => $this->depts['Drinks'], 'name' => '', 'hidden' => true],
        ['id' => $this->depts['Dairy'], 'name' => '', 'hidden' => false],
    ]]);

    $props = ($this->home)();
    $names = collect($props['store']['departments'])->pluck('name')->all();

    expect(array_slice($names, 0, 2))->toBe(['Fresh bread', 'Dairy'])
        ->and($names)->not->toContain('Drinks')
        ->and($names)->toContain('Household')   // not picked before: the saved aisles replaced the fallback
        ->and(collect($props['departmentTiles'])->pluck('name')->take(2)->all())->toBe(['Fresh bread', 'Dairy'])
        ->and(collect($props['departmentRows'])->pluck('name')->take(2)->all())->toBe(['Fresh bread', 'Dairy']);
});

test('the old picks are ignored when the aisles are categories, or none of them is an aisle', function () {
    $fruit = OnlineStoreFixtures::category($this->f->company, $this->f->department, 'Fruit');
    $this->f->product('Apple', ['category_id' => $fruit]);
    ($this->set)(['home_departments' => json_encode([$this->depts['Drinks']]), 'aisle_source' => 'categories']);

    expect(collect(($this->home)()['store']['departments'])->pluck('name')->all())->toBe(['Cupboard', 'Fruit']);

    ($this->set)(['home_departments' => json_encode([$this->none, 'NOT-A-DEPARTMENT']), 'aisle_source' => null]);
    expect(collect(($this->home)()['store']['departments'])->pluck('name')->all())->toHaveCount(8);
});

test('another business\'s department in the old picks never shows and hides nothing', function () {
    $other = OnlineStoreFixtures::make('Other Shop', 'other-shop');
    $other->product('Theirs');
    ($this->set)(['home_departments' => json_encode([$other->department])]);

    $names = collect(($this->home)()['store']['departments'])->pluck('name')->all();
    expect($names)->toHaveCount(8)->and($names)->toContain('Grocery');

    // And business B's store never gets A's picks.
    OnlineStore::withoutCompanyScope()->whereKey($other->store->id)->update(['home_departments' => json_encode([$this->depts['Drinks']])]);
    $this->get(route('store.home', ['slug' => 'other-shop'], false))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('store.departments', fn ($d) => collect($d)->pluck('name')->all() === ['Grocery']));
});
