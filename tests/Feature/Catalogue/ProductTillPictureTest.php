<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillImages\Actions\StoreTillProductImage;
use App\Domain\TillImages\Data\TillImageUpload;
use App\Domain\TillImages\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\TillImageSamples as Img;

/*
 * IMG-1: the portal's Products list and product page, and the online store's products page, show the picture the
 * till sent (read only) while TILL_PRODUCT_IMAGES is on; nothing while off; never another business's.
 */

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('public');
    config(['sync.product_images.enabled' => true]);
    $this->f = OnlineStoreFixtures::make();
    $this->owner = F::member($this->f->company, CompanyRole::Owner);
    $this->bread = $this->f->product('Bread');
    $this->milk = $this->f->product('Milk');
    $jpeg = Img::jpeg(80, 60);
    app(StoreTillProductImage::class)->handle($this->f->company, new TillImageUpload($this->bread, $jpeg, 'image/jpeg', hash('sha256', $jpeg)));
    $this->url = Storage::disk('public')->url(ProductImage::withoutCompanyScope()->sole()->path);
});

test('the Products list and product page show the till picture', function () {
    $this->actingAs($this->owner)->get('/app/products')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('products.data', fn ($rows) => collect($rows)->pluck('imageUrl', 'name')->all() === ['Bread' => $this->url, 'Milk' => null]));

    $this->actingAs($this->owner)->get("/app/products/{$this->bread}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('product.tillImage.url', $this->url)->where('product.tillImage.width', 80)->where('product.tillImage.height', 60));
    $this->actingAs($this->owner)->get("/app/products/{$this->milk}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('product.tillImage', null));
});

test('the online store products page offers the till picture beside the online photo', function () {
    $this->actingAs($this->owner)->get('/app/online-store/products')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('products.data', fn ($rows) => collect($rows)->pluck('tillPhotoUrl', 'name')->all() === ['Bread' => $this->url, 'Milk' => null]));
});

test('switched off: no till picture anywhere in the portal', function () {
    config(['sync.product_images.enabled' => false]);

    $this->actingAs($this->owner)->get('/app/products')->assertInertia(fn (Assert $page) => $page
        ->where('products.data', fn ($rows) => collect($rows)->pluck('imageUrl')->filter()->all() === []));
    $this->actingAs($this->owner)->get("/app/products/{$this->bread}")->assertInertia(fn (Assert $page) => $page->where('product.tillImage', null));
});

test('another business never sees this business\'s till picture', function () {
    $other = OnlineStoreFixtures::make('Stoney Mini Mart', 'stoney');
    $theirs = $other->product('Bread');
    ProductImage::withoutCompanyScope()->update(['product_id' => $theirs]);
    $member = F::member($other->company, CompanyRole::Owner);

    $this->actingAs($member)->get('/app/products')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('products.data.0.imageUrl', null));
    $this->actingAs($member)->get("/app/products/{$theirs}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('product.tillImage', null));
    $this->actingAs($member)->get("/app/products/{$this->bread}")->assertNotFound();
});
