<?php

use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\OnlineStore\Support\OnlineCatalogueQuery;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/*
 * OS-3: the online store's products page: what the store shows at a shop and why not (OnlineCatalogueQuery's rules),
 * per product hidden or shown, photo and short description (SetOnlineProduct), and bulk hide / show.
 */

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('public');
    $this->f = OnlineStoreFixtures::make();
    $this->company = $this->f->company;
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $drinks = OnlineStoreFixtures::department($this->company, 'Drinks', 2);
    $this->milk = $this->f->product('Milk', ['sku' => 'MLK-1', 'description' => 'Semi-skimmed']);
    $this->lager = $this->f->product('Lager', ['is_alcohol' => true, 'department_id' => $drinks]);
    $this->mystery = $this->f->product('Mystery box', ['sell_price' => '0.00']);
    $this->bread = $this->f->product('Bread');
    $this->f->product('Old stock', ['is_active' => false]);
    $this->f->shopPrice($this->f->bradford, $this->mystery, '2.50');
    app(SetOnlineProduct::class)->handle($this->company, $this->bread, ['hidden' => true]);
    $this->rows = fn (array $query = []) => $this->actingAs($this->owner)->get('/app/online-store/products?'.http_build_query($query))->assertOk()
        ->viewData('page')['props'];
    $this->row = fn (string $id, ?Company $company = null) => OnlineStoreProduct::withoutCompanyScope()->where('company_id', ($company ?? $this->company)->id)->where('product_id', $id)->first();
});

test('the list shows every active product at the collection shop: price, shown or not and why', function () {
    $props = ($this->rows)();
    $byName = collect($props['products']['data'])->keyBy('name');

    expect($props['products']['meta']['total'])->toBe(4)
        ->and($props['filters'])->toBe(['shop' => $this->f->leeds->id, 'status' => 'all', 'department' => null])
        ->and($props['counts'])->toBe(['total' => 4, 'shown' => 1, 'hidden' => 1])
        ->and($byName->keys()->all())->toBe(['Bread', 'Lager', 'Milk', 'Mystery box'])
        ->and($byName['Milk'])->toMatchArray(['shown' => true, 'hidden' => false, 'reasons' => [], 'price' => '1.00', 'department' => 'Grocery', 'sku' => 'MLK-1', 'tillDescription' => 'Semi-skimmed'])
        ->and($byName['Lager'])->toMatchArray(['shown' => false, 'reasons' => ['Alcohol'], 'department' => 'Drinks'])
        ->and($byName['Mystery box'])->toMatchArray(['shown' => false, 'reasons' => ['No price at this shop'], 'price' => '0.00'])
        ->and($byName['Bread'])->toMatchArray(['shown' => false, 'hidden' => true, 'reasons' => []])
        ->and(collect($props['shops'])->pluck('collects', 'name')->all())->toBe(['Bradford' => false, 'Leeds' => true]);

    // Bradford has its own price for the mystery box.
    $bradford = collect(($this->rows)(['shop' => $this->f->bradford->id])['products']['data'])->keyBy('name');
    expect($bradford['Mystery box'])->toMatchArray(['shown' => true, 'price' => '2.50']);
});

test('exclusions() names every rule a product fails, in order', function () {
    $both = $this->f->product('Cider', ['is_alcohol' => true, 'sell_price' => '0.00', 'age_rule' => 'over18']);

    $reasons = app(CurrentCompany::class)->runAs($this->company, fn () => OnlineCatalogueQuery::exclusions($this->f->shop, [$this->milk, $both, $this->bread]));

    expect($reasons)->toBe([
        $this->milk => [],
        $both => ['ageRestricted', 'alcohol', 'noPrice'],
        $this->bread => ['hidden'],
    ]);
});

test('filters: shown, not shown, hidden by you, department and search', function () {
    $names = fn (array $query) => collect(($this->rows)($query)['products']['data'])->pluck('name')->all();

    expect($names(['status' => 'shown']))->toBe(['Milk'])
        ->and($names(['status' => 'notShown']))->toBe(['Bread', 'Lager', 'Mystery box'])
        ->and($names(['status' => 'hidden']))->toBe(['Bread'])
        ->and($names(['department' => $this->f->department]))->toBe(['Bread', 'Milk', 'Mystery box'])
        ->and($names(['search' => 'mlk']))->toBe(['Milk'])
        ->and($names(['search' => 'myst']))->toBe(['Mystery box'])
        ->and($names(['status' => 'nonsense', 'shop' => 'not-a-shop']))->toHaveCount(4);
});

test('saving online details: description, photo stored per business, replaced photo deleted, photo removed', function () {
    $this->actingAs($this->owner)->post("/app/online-store/products/{$this->milk}", [
        'description' => '  Fresh from a Yorkshire dairy. ', 'photo' => UploadedFile::fake()->image('../../milk photo.JPG', 400, 400),
    ])->assertRedirect()->assertSessionHas('success', 'Online details saved.');

    $first = ($this->row)($this->milk);
    expect($first->description)->toBe('Fresh from a Yorkshire dairy.')->and($first->hidden)->toBeFalse()
        ->and($first->photo_path)->toMatch('#^online-store/'.$this->company->id.'/products/[0-9a-z]{26}\.webp$#');
    Storage::disk('public')->assertExists($first->photo_path);

    $milk = collect(($this->rows)()['products']['data'])->firstWhere('name', 'Milk');
    expect($milk['photoUrl'])->toBe(Storage::disk('public')->url($first->photo_path))->and($milk['description'])->toBe('Fresh from a Yorkshire dairy.');

    $this->actingAs($this->owner)->post("/app/online-store/products/{$this->milk}", ['photo' => UploadedFile::fake()->image('new.png', 400, 400)])->assertSessionHasNoErrors();
    $second = ($this->row)($this->milk);
    Storage::disk('public')->assertMissing($first->photo_path);
    Storage::disk('public')->assertExists($second->photo_path);
    expect($second->description)->toBe('Fresh from a Yorkshire dairy.');

    $this->actingAs($this->owner)->post("/app/online-store/products/{$this->milk}", ['remove_photo' => true, 'hidden' => true])->assertSessionHasNoErrors();
    Storage::disk('public')->assertMissing($second->photo_path);
    expect(($this->row)($this->milk)->photo_path)->toBeNull()->and(($this->row)($this->milk)->hidden)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'online_store.product_updated')->where('company_id', $this->company->id)->count())->toBe(4);
});

test('online details are checked (422) and a refused save keeps no file: :dataset', function (Closure $input, string $field) {
    $this->actingAs($this->owner)->postJson("/app/online-store/products/{$this->milk}", $input())->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(Storage::disk('public')->allFiles())->toBe([])->and(($this->row)($this->milk))->toBeNull();
})->with([
    'a PDF photo' => [fn () => ['photo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], 'photo'],
    'a photo over 4 MB' => [fn () => ['photo' => UploadedFile::fake()->image('a.jpg', 100, 100)->size(5000)], 'photo'],
    'description too long' => [fn () => ['description' => str_repeat('a', 1001), 'photo' => UploadedFile::fake()->image('a.jpg', 100, 100)], 'description'],
    'hidden not yes or no' => [fn () => ['hidden' => 'maybe'], 'hidden'],
]);

test('bulk hide and show; counts only real changes', function () {
    $this->actingAs($this->owner)->post('/app/online-store/products/visibility', ['ids' => [$this->milk, $this->lager, $this->bread], 'hidden' => true])
        ->assertSessionHas('success', '2 products hidden online.');
    expect(($this->row)($this->milk)->hidden)->toBeTrue()->and(($this->row)($this->lager)->hidden)->toBeTrue();

    $this->actingAs($this->owner)->post('/app/online-store/products/visibility', ['ids' => [$this->milk], 'hidden' => false])
        ->assertSessionHas('success', '1 product shown online again.');
    expect(($this->row)($this->milk)->hidden)->toBeFalse();

    $this->actingAs($this->owner)->postJson('/app/online-store/products/visibility', ['ids' => [], 'hidden' => true])->assertUnprocessable()->assertJsonValidationErrors('ids');
    $this->actingAs($this->owner)->postJson('/app/online-store/products/visibility', ['ids' => [$this->milk]])->assertUnprocessable()->assertJsonValidationErrors('hidden');
    $this->actingAs($this->owner)->postJson('/app/online-store/products/visibility', ['ids' => array_fill(0, 101, $this->milk), 'hidden' => true])->assertUnprocessable();
});

test('a one-shop manager may look at products but not change them', function () {
    $oneShop = F::member($this->company, CompanyRole::Manager, $this->f->bradford);

    $this->actingAs($oneShop)->get('/app/online-store/products')->assertInertia(fn (Assert $p) => $p->where('canEdit', false)
        ->has('shops', 1)->where('filters.shop', $this->f->bradford->id));
    $this->actingAs($oneShop)->postJson("/app/online-store/products/{$this->milk}", ['hidden' => true])->assertForbidden();
    $this->actingAs($oneShop)->postJson('/app/online-store/products/visibility', ['ids' => [$this->milk], 'hidden' => true])->assertForbidden();
    expect(($this->row)($this->milk))->toBeNull();
});

test('company A can never read or change company B\'s product settings; a B product id is refused', function () {
    $b = OnlineStoreFixtures::make('Other Shop', 'other-shop');
    $bProduct = $b->product('B only');
    app(SetOnlineProduct::class)->handle($b->company, $bProduct, ['description' => 'B text']);

    $props = ($this->rows)(['search' => 'B only']);
    expect($props['products']['data'])->toBe([]);
    expect(collect(($this->rows)()['products']['data'])->pluck('id'))->not->toContain($bProduct);
    expect(collect(($this->rows)(['shop' => $b->leeds->id])['filters'])->get('shop'))->toBe($this->f->leeds->id);

    $this->actingAs($this->owner)->postJson("/app/online-store/products/{$bProduct}", ['hidden' => true, 'photo' => UploadedFile::fake()->image('x.png', 50, 50)])
        ->assertUnprocessable()->assertJsonPath('errors.product_id.0', 'Choose one of your products.');
    $this->actingAs($this->owner)->postJson('/app/online-store/products/visibility', ['ids' => [$this->milk, $bProduct], 'hidden' => true])
        ->assertUnprocessable()->assertJsonPath('errors.ids.0', 'Choose your own products.');

    expect(($this->row)($bProduct, $b->company))->toMatchArray(['hidden' => false, 'description' => 'B text'])
        ->and(($this->row)($this->milk))->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(DB::table('online_store_products')->where('company_id', $this->company->id)->where('product_id', $bProduct)->exists())->toBeFalse();
});
