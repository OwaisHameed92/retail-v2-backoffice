<?php

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillImages\Actions\StoreTillProductImage;
use App\Domain\TillImages\Data\TillImageUpload;
use App\Domain\TillImages\Enums\ProductImageOrigin;
use App\Domain\TillImages\Models\ProductImage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Purchasing\PurchasingFixtures;
use Tests\Feature\Sync\TillImageSamples as Img;

/*
 * IMG-2: the public store shows a till picture only when the shop chose it (`own`) or it is Open Food Facts'
 * (`library`, credited "Photo: Open Food Facts (CC BY-SA)"); `found` and `unknown` only with the store's "Show found
 * pictures". A hidden picture falls through to the master catalogue's picture of a barcode, else the placeholder,
 * for products and department tiles alike.
 */

beforeEach(function () {
    Storage::fake('public');
    config(['sync.product_images.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->till = function (string $productId, ProductImageOrigin $origin): string {
        $jpeg = Img::jpeg(30 + strlen($productId) % 7, 30);
        app(StoreTillProductImage::class)->handle($this->f->company, new TillImageUpload($productId, $jpeg, 'image/jpeg', hash('sha256', $jpeg), $origin));

        return Storage::disk('public')->url(ProductImage::withoutCompanyScope()->where('product_id', $productId)->sole()->path);
    };
    $this->master = function (string $productId, string $barcode, string $url): void {
        DB::table('product_barcodes')->insert(['id' => Ulid::new(), 'company_id' => $this->f->company->id, 'product_id' => $productId, 'barcode' => $barcode, 'row_version' => 1]);
        DB::table('master_products')->insert(['id' => strtolower(Ulid::new()), 'barcode' => $barcode, 'name' => 'Master '.$barcode, 'image_url' => $url,
            'source' => 'admin', 'age_rule' => 'none']);
    };
    $this->products = fn () => collect($this->get(route('store.search', ['slug' => 'kirkgate'], false))->assertOk()->viewData('page')['props']['products']);
    $this->showFound = fn (bool $on) => OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['show_found_pictures' => $on]);
});

test('by default: own and library pictures are shown, found and unknown fall through to the master catalogue or the placeholder', function () {
    $own = $this->f->product('A own');
    $library = $this->f->product('B library');
    $found = $this->f->product('C found with master');
    $unknown = $this->f->product('D unknown');
    $ownUrl = ($this->till)($own, ProductImageOrigin::Own);
    $libraryUrl = ($this->till)($library, ProductImageOrigin::Library);
    ($this->till)($found, ProductImageOrigin::Found);
    ($this->till)($unknown, ProductImageOrigin::Unknown);
    ($this->master)($found, '5000157024671', 'https://images.example.test/beans.jpg');

    $products = ($this->products)();

    expect($products->pluck('photoUrl', 'name')->all())->toEqual([
        'A own' => $ownUrl,
        'B library' => $libraryUrl,
        'C found with master' => 'https://images.example.test/beans.jpg',
        'D unknown' => null,
    ])->and($products->pluck('photoCredit', 'name')->all())->toEqual([
        'A own' => null,
        'B library' => 'Photo: Open Food Facts (CC BY-SA)',
        'C found with master' => null,
        'D unknown' => null,
    ]);
});

test('with "Show found pictures" on, found and unknown pictures are shown too', function () {
    $found = $this->f->product('C found with master');
    $unknown = $this->f->product('D unknown');
    $foundUrl = ($this->till)($found, ProductImageOrigin::Found);
    $unknownUrl = ($this->till)($unknown, ProductImageOrigin::Unknown);
    ($this->master)($found, '5000157024671', 'https://images.example.test/beans.jpg');
    ($this->showFound)(true);

    expect(($this->products)()->pluck('photoUrl', 'name')->all())->toEqual(['C found with master' => $foundUrl, 'D unknown' => $unknownUrl]);
});

test('the product page credits an Open Food Facts photo under it, and the footer credits it once', function () {
    $library = $this->f->product('Beans');
    $own = $this->f->product('Bread');
    ($this->till)($library, ProductImageOrigin::Library);
    ($this->till)($own, ProductImageOrigin::Own);

    $this->get(route('store.product', ['slug' => 'kirkgate', 'product' => $library], false))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('store/product')
            ->where('product.photoCredit', 'Photo: Open Food Facts (CC BY-SA)')
            ->where('store.photoCredit', 'Some product photos: Open Food Facts (CC BY-SA)'));
    $this->get(route('store.product', ['slug' => 'kirkgate', 'product' => $own], false))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('product.photoCredit', null));

    // No Open Food Facts picture (or pictures switched off): no footer line.
    ProductImage::withoutCompanyScope()->where('product_id', $library)->update(['origin' => 'own']);
    $this->get(route('store.home', ['slug' => 'kirkgate'], false))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('store.photoCredit', null));
    ProductImage::withoutCompanyScope()->where('product_id', $library)->update(['origin' => 'library']);
    config(['sync.product_images.enabled' => false]);
    $this->get(route('store.home', ['slug' => 'kirkgate'], false))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('store.photoCredit', null));
});

test('the basket shows the same photo and credit', function () {
    $library = $this->f->product('Beans');
    $url = ($this->till)($library, ProductImageOrigin::Library);
    $this->from(route('store.home', ['slug' => 'kirkgate'], false))
        ->post(route('store.basket.add', ['slug' => 'kirkgate'], false), ['product' => $library, 'quantity' => '1'])->assertSessionHasNoErrors();

    $this->get(route('store.basket', ['slug' => 'kirkgate'], false))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('lines.0.product.photoUrl', $url)->where('lines.0.product.photoCredit', 'Photo: Open Food Facts (CC BY-SA)'));
});

test('a department tile follows the same rule', function () {
    // OS-11: the department tiles show from three aisles up (StoreAisles::GOOD).
    $this->f->product('Apples', ['department_id' => OnlineStoreFixtures::department($this->f->company, 'Fresh', 2)]);
    $this->f->product('Milk', ['department_id' => OnlineStoreFixtures::department($this->f->company, 'Dairy', 3)]);
    $bread = $this->f->product('Bread');
    $foundUrl = ($this->till)($bread, ProductImageOrigin::Found);
    $tile = fn () => collect($this->get(route('store.home', ['slug' => 'kirkgate'], false))->assertOk()->viewData('page')['props']['departmentTiles'])
        ->firstWhere('name', 'Grocery')['photoUrl'] ?? null;

    expect($tile())->toBeNull();

    ($this->master)($bread, '5010044000701', 'https://images.example.test/bread.jpg');
    expect($tile())->toBe('https://images.example.test/bread.jpg');

    ($this->showFound)(true);
    expect($tile())->toBe($foundUrl);

    ($this->showFound)(false);
    ProductImage::withoutCompanyScope()->where('product_id', $bread)->update(['origin' => 'own']);
    expect($tile())->toBe($foundUrl);
});

test('the portal\'s own product page still shows a found picture, with its origin label', function () {
    $bread = $this->f->product('Bread');
    $url = ($this->till)($bread, ProductImageOrigin::Found);
    $owner = PurchasingFixtures::member($this->f->company, CompanyRole::Owner);
    $this->withoutVite();

    $this->actingAs($owner)->get("/app/products/{$bread}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.tillImage.url', $url)->where('product.tillImage.origin', 'found')->where('product.tillImage.originLabel', 'Found online'));
    $this->actingAs($owner)->get('/app/products')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('products.data', fn ($rows) => collect($rows)->pluck('imageUrl', 'name')->all() === ['Bread' => $url]));
});
