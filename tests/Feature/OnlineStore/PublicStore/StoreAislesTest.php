<?php

use App\Domain\OnlineStore\Actions\SaveStoreAisles;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
| OS-11: the store's aisles. Never the till's "no department" bucket, names in Title Case, only aisles with something
| to order, categories when the till has too few real departments, the shop's own order, names, photos and hidden
| aisles, the big tiles only from three aisles up, and products with no aisle still found by search.
*/

beforeEach(function () {
    Storage::fake('public');
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->dept = fn (string $name, int $pos) => OnlineStoreFixtures::department($this->f->company, $name, $pos);
    $this->aisles = fn () => $this->get(($this->url)('store.home'))->viewData('page')['props']['store']['departments'];
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)
        ->update(['sections' => json_encode([['key' => 'departments', 'enabled' => true]])]);
});

test('never the till\'s "no department" bucket; Title Case names; only aisles with something in stock', function () {
    $none = ($this->dept)('NO-DEPARTMENT', 0);
    $frozen = ($this->dept)('FROZEN FOOD', 2);
    $empty = ($this->dept)('Bakery', 3);
    $dairy = ($this->dept)('Dairy', 4);
    $this->f->product('Loose item', ['department_id' => $none]);
    $this->f->product('Peas', ['department_id' => $frozen]);
    $this->f->product('Bread', ['department_id' => $empty, 'track_stock' => true]);   // no stock
    $this->f->product('Milk', ['department_id' => $dairy]);
    $this->f->product('Beans');

    expect(collect(($this->aisles)())->pluck('name')->all())->toBe(['Grocery', 'Frozen Food', 'Dairy']);
    $this->get(($this->url)('store.department', ['department' => $none]))->assertNotFound();
    $this->get(($this->url)('store.search', ['q' => 'loose']))->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.name', 'Loose item'));
    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page->has('departmentTiles', 3));
});

test('with fewer than three real departments, the till\'s categories make the aisles; tiles only from three up', function () {
    $fruit = OnlineStoreFixtures::category($this->f->company, $this->f->department, 'Fruit');
    $veg = OnlineStoreFixtures::category($this->f->company, $this->f->department, 'VEG');
    $this->f->product('Beans');
    $apple = $this->f->product('Apple', ['category_id' => $fruit]);
    $this->f->product('Carrot', ['category_id' => $veg]);

    expect(collect(($this->aisles)())->pluck('name')->all())->toBe(['Cupboard', 'Fruit', 'Veg']);
    $this->actingAs(C::member($this->f->company, CompanyRole::Owner))->get('/app/online-store')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('aisleSourceUsed', 'categories')->where('aisleSource', 'auto'));
    $this->get(($this->url)('store.department', ['department' => $fruit]))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('department.name', 'Fruit')
        ->where('products', fn ($items) => collect($items)->pluck('name')->all() === ['Apple']));
    $this->get(($this->url)('store.product', ['product' => $apple]))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('department.name', 'Fruit'));

    // Departments chosen: one real department → no big tiles.
    app(SaveStoreAisles::class)->handle($this->f->company, ['source' => 'departments', 'aisles' => []]);
    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('store.departments', fn ($d) => collect($d)->pluck('name')->all() === ['Grocery'])
        ->where('departmentTiles', []));
});

test('the shop orders, renames, hides aisles and adds a photo; one-shop users and staff cannot', function () {
    $frozen = ($this->dept)('FROZEN', 2);
    $dairy = ($this->dept)('Dairy', 3);
    $this->f->product('Peas', ['department_id' => $frozen]);
    $this->f->product('Milk', ['department_id' => $dairy]);
    $this->f->product('Beans');
    $owner = C::member($this->f->company, CompanyRole::Owner);

    $this->actingAs($owner)->put('/app/online-store/aisles', ['source' => null, 'aisles' => [
        ['id' => $dairy, 'name' => 'Milk & eggs', 'hidden' => false],
        ['id' => $frozen, 'name' => '', 'hidden' => true],
    ]])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post("/app/online-store/aisles/{$dairy}/photo", ['photo' => UploadedFile::fake()->image('d.jpg', 900, 900)])
        ->assertSessionHasNoErrors();

    expect(collect(($this->aisles)())->pluck('name')->all())->toBe(['Milk & eggs', 'Grocery']);
    $this->actingAs($owner)->get('/app/online-store')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('aisles', fn ($a) => collect($a)->pluck('hidden', 'tillName')->all() === ['Dairy' => false, 'FROZEN' => true, 'Grocery' => false])
        ->where('aisles.0.photoUrl', fn ($url) => str_contains((string) $url, "online-store/{$this->f->company->id}/products/"))
        ->where('aisleSourceUsed', 'departments'));

    $staff = C::member($this->f->company, CompanyRole::Staff);
    $this->actingAs($staff)->put('/app/online-store/aisles', ['aisles' => []])->assertForbidden();
    $oneShop = C::member($this->f->company, CompanyRole::Manager, $this->f->leeds->id);
    $this->actingAs($oneShop)->put('/app/online-store/aisles', ['aisles' => []])->assertForbidden();
});

test('another business\'s department is refused', function () {
    $other = OnlineStoreFixtures::make('Harehills Halal', 'harehills');

    expect(fn () => app(SaveStoreAisles::class)->handle($this->f->company, ['aisles' => [['id' => $other->department, 'hidden' => true]]]))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveStoreAisles::class)->photo($this->f->company, $other->department, null))->toThrow(ValidationException::class);
});
