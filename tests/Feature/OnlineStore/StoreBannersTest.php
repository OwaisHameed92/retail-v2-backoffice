<?php

use App\Domain\OnlineStore\Actions\SaveStoreBanner;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreBanner;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/*
 * OS-8: the store builder's home banners (add, change, photo, on/off, order, delete, at most 6), who may change them,
 * and that one business never sees or changes another's.
 */

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('public');
    $this->f = OnlineStoreFixtures::make();
    $this->owner = F::member($this->f->company, CompanyRole::Owner);
    $this->banners = fn ($company = null) => OnlineStoreBanner::withoutCompanyScope()->where('company_id', ($company ?? $this->f->company)->id)
        ->orderBy('position')->get();
});

test('adding a banner stores its photo under the business, puts it last and audits it; the builder lists it', function () {
    $this->actingAs($this->owner)->post('/app/online-store/banners', [
        'headline' => 'Fresh bread daily', 'text' => 'Baked every morning.', 'button_label' => 'Shop bakery',
        'link_type' => 'department', 'link_target' => $this->f->department, 'is_on' => '1',
        'photo' => UploadedFile::fake()->image('bread.jpg', 1600, 700),
    ])->assertSessionHasNoErrors()->assertSessionHas('success');
    $this->actingAs($this->owner)->post('/app/online-store/banners', ['headline' => 'Second', 'link_type' => 'search', 'link_target' => 'beans'])
        ->assertSessionHasNoErrors();

    [$first, $second] = ($this->banners)()->all();
    expect($first->position)->toBe(0)->and($second->position)->toBe(1)
        ->and($first->photo_path)->toStartWith("online-store/{$this->f->company->id}/banner/")
        ->and($first->link_type->value)->toBe('department')
        ->and(AuditLog::query()->where('action', 'online_store.banner_added')->count())->toBe(2);
    Storage::disk('public')->assertExists($first->photo_path);

    $this->actingAs($this->owner)->get('/app/online-store')->assertInertia(fn (Assert $p) => $p
        ->has('banners', 2)
        ->where('banners.0.headline', 'Fresh bread daily')
        ->where('banners.0.linkLabel', 'Grocery')
        ->where('banners.1.linkLabel', 'beans')
        ->where('bannerMax', 6));
});

test('editing replaces the photo (the old file is deleted), removing it falls back to the template; off and order work', function () {
    $save = app(SaveStoreBanner::class);
    $a = $save->handle($this->f->company, null, ['headline' => 'A'], UploadedFile::fake()->image('a.jpg', 800, 400));
    $b = $save->handle($this->f->company, null, ['headline' => 'B']);
    $old = $a->photo_path;

    $this->actingAs($this->owner)->post("/app/online-store/banners/{$a->id}", ['headline' => 'A2', 'photo' => UploadedFile::fake()->image('a2.jpg', 800, 400)])
        ->assertSessionHasNoErrors();
    $a->refresh();
    Storage::disk('public')->assertMissing($old);
    Storage::disk('public')->assertExists($a->photo_path);

    $this->actingAs($this->owner)->post("/app/online-store/banners/{$a->id}", ['headline' => 'A2', 'remove_photo' => '1', 'is_on' => '0'])->assertSessionHasNoErrors();
    expect($a->refresh()->photo_path)->toBeNull()->and($a->is_on)->toBeFalse();

    $this->actingAs($this->owner)->put('/app/online-store/banners/order', ['ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();
    expect(($this->banners)()->pluck('id')->all())->toBe([$b->id, $a->id]);

    $this->actingAs($this->owner)->putJson('/app/online-store/banners/order', ['ids' => [$b->id]])->assertUnprocessable()->assertJsonValidationErrors('ids');

    $this->actingAs($this->owner)->delete("/app/online-store/banners/{$b->id}")->assertSessionHas('success', 'Banner deleted.');
    expect(($this->banners)()->pluck('position', 'id')->all())->toBe([$a->id => 0]);
});

test('at most six banners; links are checked (own department or product, https only); bad files are refused', function () {
    $other = OnlineStoreFixtures::make('Other Shop', 'other-shop');
    $theirs = $other->product('Their beans');
    $save = app(SaveStoreBanner::class);

    foreach (range(1, 6) as $i) {
        $save->handle($this->f->company, null, ['headline' => "Banner {$i}"]);
    }

    $this->actingAs($this->owner)->postJson('/app/online-store/banners', ['headline' => 'Seventh'])
        ->assertUnprocessable()->assertJsonPath('errors.banners.0', SaveStoreBanner::TOO_MANY);

    $banner = ($this->banners)()->first();
    $url = "/app/online-store/banners/{$banner->id}";

    $this->actingAs($this->owner)->postJson($url, ['link_type' => 'department', 'link_target' => $other->department])->assertJsonValidationErrors('link_target');
    $this->actingAs($this->owner)->postJson($url, ['link_type' => 'product', 'link_target' => $theirs])->assertJsonValidationErrors('link_target');
    $this->actingAs($this->owner)->postJson($url, ['link_type' => 'url', 'link_target' => 'http://example.test'])->assertJsonValidationErrors('link_target');
    $this->actingAs($this->owner)->postJson($url, ['link_type' => 'url', 'link_target' => 'javascript:alert(1)'])->assertJsonValidationErrors('link_target');
    $this->actingAs($this->owner)->postJson($url, ['photo' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf')])->assertJsonValidationErrors('photo');
    $this->actingAs($this->owner)->postJson($url, ['link_type' => 'url', 'link_target' => 'https://example.test/offers'])->assertSessionHasNoErrors();
    expect($banner->refresh()->link_target)->toBe('https://example.test/offers');
});

test('staff get 403, a one-shop manager may not change banners, guests go to the login page', function () {
    $banner = app(SaveStoreBanner::class)->handle($this->f->company, null, ['headline' => 'Kept']);
    $calls = [
        ['postJson', '/app/online-store/banners', ['headline' => 'x']],
        ['postJson', "/app/online-store/banners/{$banner->id}", ['headline' => 'x']],
        ['putJson', '/app/online-store/banners/order', ['ids' => [$banner->id]]],
        ['deleteJson', "/app/online-store/banners/{$banner->id}", []],
    ];

    foreach ($calls as [$method, $url, $body]) {
        $this->{$method}($url, $body)->assertStatus(401);
    }

    foreach ($calls as [$method, $url, $body]) {
        $this->actingAs(F::member($this->f->company, CompanyRole::Staff))->{$method}($url, $body)->assertForbidden();
        $this->actingAs(F::member($this->f->company, CompanyRole::Manager, $this->f->leeds))->{$method}($url, $body)->assertForbidden();
    }

    $this->actingAs(F::member($this->f->company, CompanyRole::Staff))->getJson('/app/online-store/product-search')->assertForbidden();
    expect($banner->refresh()->headline)->toBe('Kept')->and(($this->banners)())->toHaveCount(1);
});

test('business A can never see, change, order or delete business B\'s banners', function () {
    $b = OnlineStoreFixtures::make('Other Shop', 'other-shop');
    $theirs = app(SaveStoreBanner::class)->handle($b->company, null, ['headline' => 'B only'], UploadedFile::fake()->image('b.jpg', 800, 400));

    $this->actingAs($this->owner)->get('/app/online-store')->assertInertia(fn (Assert $p) => $p->has('banners', 0));
    $this->actingAs($this->owner)->postJson("/app/online-store/banners/{$theirs->id}", ['headline' => 'Hacked'])->assertJsonValidationErrors('banner');
    $this->actingAs($this->owner)->deleteJson("/app/online-store/banners/{$theirs->id}")->assertJsonValidationErrors('banner');
    $this->actingAs($this->owner)->putJson('/app/online-store/banners/order', ['ids' => [$theirs->id]])->assertJsonValidationErrors('ids');

    expect($theirs->refresh()->headline)->toBe('B only');
    Storage::disk('public')->assertExists($theirs->photo_path);
});

test('the product picker lists only this store\'s shown products', function () {
    $beans = $this->f->product('Baked beans');
    $this->f->product('Whisky', ['is_alcohol' => true]);
    OnlineStoreFixtures::make('Other Shop', 'other-shop')->product('Baked beans too');

    $this->actingAs($this->owner)->getJson('/app/online-store/product-search?q=bea')->assertOk()
        ->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $beans);
    $this->actingAs($this->owner)->getJson('/app/online-store/product-search?q=whisky')->assertOk()->assertJsonCount(0, 'products');
});

test('the migration moves a store\'s single banner and headline into its first banner', function () {
    $migration = require database_path('migrations/2026_12_09_120000_create_online_store_banners_table.php');
    $migration->down();
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['banner_path' => 'online-store/x/banner/old.jpg', 'headline' => 'Old words']);
    $migration->up();

    $row = DB::table('online_store_banners')->where('online_store_id', $this->f->store->id)->sole();
    expect($row->photo_path)->toBe('online-store/x/banner/old.jpg')->and($row->headline)->toBe('Old words')->and((int) $row->position)->toBe(0)
        ->and((bool) $row->is_on)->toBeTrue()->and($row->company_id)->toBe($this->f->company->id);
});

test('a store with banners forgets its old single banner and headline', function () {
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['banner_path' => 'online-store/x/banner/old.jpg', 'headline' => 'Old']);
    app(SaveStoreBanner::class)->handle($this->f->company, null, ['headline' => 'New']);

    $store = OnlineStore::withoutCompanyScope()->find($this->f->store->id);
    expect($store->banner_path)->toBeNull()->and($store->headline)->toBeNull();
    expect(fn () => app(SaveStoreBanner::class)->handle($this->f->company, '01JAAAAAAAAAAAAAAAAAAAAAAA', ['headline' => 'x']))->toThrow(ValidationException::class);
});
