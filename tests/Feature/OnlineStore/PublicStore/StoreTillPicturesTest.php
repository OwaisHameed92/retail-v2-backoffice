<?php

use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use App\Domain\Shared\Support\Ulid;
use App\Domain\TillImages\Actions\StoreTillProductImage;
use App\Domain\TillImages\Data\TillImageUpload;
use App\Domain\TillImages\Models\ProductImage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Sync\TillImageSamples as Img;

/*
 * IMG-1: the public store's photo order is the shop's online photo > the till's picture > the master catalogue's
 * picture of a barcode > none (placeholder), for products and department tiles. While TILL_PRODUCT_IMAGES is off the
 * till's pictures are never read (the OS-8 order, exactly). Never another business's picture.
 */

beforeEach(function () {
    Storage::fake('public');
    config(['sync.product_images.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->search = route('store.search', ['slug' => 'kirkgate'], false);
    $this->till = function (string $productId, ?OnlineStoreFixtures $f = null): string {
        $jpeg = Img::jpeg(30 + strlen($productId) % 7, 30);
        app(StoreTillProductImage::class)->handle(($f ?? $this->f)->company, new TillImageUpload($productId, $jpeg, 'image/jpeg', hash('sha256', $jpeg)));

        return Storage::disk('public')->url(ProductImage::withoutCompanyScope()->where('product_id', $productId)->sole()->path);
    };
    $this->master = function (string $productId, string $barcode, string $url): void {
        DB::table('product_barcodes')->insert(['id' => Ulid::new(), 'company_id' => $this->f->company->id, 'product_id' => $productId, 'barcode' => $barcode, 'row_version' => 1]);
        DB::table('master_products')->insert(['id' => strtolower(Ulid::new()), 'barcode' => $barcode, 'name' => 'Master '.$barcode, 'image_url' => $url,
            'source' => 'admin', 'age_rule' => 'none']);
    };
    $this->photos = fn () => $this->get($this->search)->assertOk()->viewData('page')['props']['products'];
});

test('photo order: online photo > till picture > master catalogue by barcode > none', function () {
    $online = $this->f->product('A online and till');
    $till = $this->f->product('B till and master');
    $master = $this->f->product('C master only');
    $none = $this->f->product('D nothing');

    app(SetOnlineProduct::class)->handle($this->f->company, $online, ['photo_path' => 'online-store/x/products/own.jpg']);
    ($this->till)($online);
    $tillUrl = ($this->till)($till);
    ($this->master)($till, '5000157024671', 'https://images.example.test/beans.jpg');
    ($this->master)($master, '5010044000701', 'https://images.example.test/bread.jpg');

    expect(collect(($this->photos)())->pluck('photoUrl', 'name')->all())->toBe([
        'A online and till' => Storage::disk('public')->url('online-store/x/products/own.jpg'),
        'B till and master' => $tillUrl,
        'C master only' => 'https://images.example.test/bread.jpg',
        'D nothing' => null,
    ])->and($tillUrl)->toContain("till-images/{$this->f->company->id}/{$till}-");
    expect($none)->not->toBeEmpty();
});

test('switched off: the till\'s pictures are not used (master catalogue, else none), exactly as OS-8', function () {
    $till = $this->f->product('B till and master');
    $only = $this->f->product('E till only');
    ($this->till)($till);
    ($this->till)($only);
    ($this->master)($till, '5000157024671', 'https://images.example.test/beans.jpg');
    config(['sync.product_images.enabled' => false]);

    expect(collect(($this->photos)())->pluck('photoUrl', 'name')->all())->toBe([
        'B till and master' => 'https://images.example.test/beans.jpg',
        'E till only' => null,
    ]);

    // OS-11: the department tiles show from three aisles up (StoreAisles::GOOD).
    $this->f->product('Apples', ['department_id' => OnlineStoreFixtures::department($this->f->company, 'Fresh', 2)]);
    $this->f->product('Milk', ['department_id' => OnlineStoreFixtures::department($this->f->company, 'Dairy', 3)]);

    $this->get(route('store.home', ['slug' => 'kirkgate'], false))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('departmentTiles.0.photoUrl', 'https://images.example.test/beans.jpg'));
});

test('a department tile takes a till picture when none of its products has an online photo', function () {
    $tillUrl = ($this->till)($this->f->product('Bread'));

    // OS-11: the department tiles show from three aisles up (StoreAisles::GOOD).
    $this->f->product('Apples', ['department_id' => OnlineStoreFixtures::department($this->f->company, 'Fresh', 2)]);
    $this->f->product('Milk', ['department_id' => OnlineStoreFixtures::department($this->f->company, 'Dairy', 3)]);

    $this->get(route('store.home', ['slug' => 'kirkgate'], false))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('departmentTiles.0.photoUrl', $tillUrl));
});

test('another business\'s till picture of the same product id is never shown', function () {
    $other = OnlineStoreFixtures::make('Stoney Mini Mart', 'stoney');
    $id = Ulid::new();
    $other->product('Theirs', ['id' => $id]);
    ($this->till)($id, $other);
    // Their picture row made to name one of our products: the store still filters on the business, never on the id alone.
    DB::table('product_images')->where('product_id', $id)->update(['product_id' => $this->f->product('Ours')]);

    expect(collect(($this->photos)())->pluck('photoUrl', 'name')->all())->toBe(['Ours' => null]);
});
