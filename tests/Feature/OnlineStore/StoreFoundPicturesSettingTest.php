<?php

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/*
 * IMG-2: the store builder's "Show found pictures" (online_stores.show_found_pictures): saved with "Save changes",
 * audited, `store.manage` only, never another business's store, and only while TILL_PRODUCT_IMAGES is on (hidden and
 * ignored otherwise).
 */

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('public');
    config(['sync.product_images.enabled' => true]);
    $this->f = OnlineStoreFixtures::make();
    $this->owner = F::member($this->f->company, CompanyRole::Owner);
    $this->save = fn (array $overrides = []) => [
        'slug' => 'kirkgate', 'sections' => [['key' => 'hero', 'enabled' => true]], ...$overrides,
    ];
    $this->stored = fn (?OnlineStoreFixtures $f = null) => (bool) OnlineStore::withoutCompanyScope()->where('company_id', ($f ?? $this->f)->company->id)
        ->sole()->show_found_pictures;
});

test('off by default; the builder shows the switch while the till\'s pictures are on', function () {
    expect(($this->stored)())->toBeFalse();

    $this->actingAs($this->owner)->get('/app/online-store')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('tillPictures', true)->where('store.showFoundPictures', false));
});

test('saving turns it on and off, audited like the other store settings', function () {
    $this->actingAs($this->owner)->post('/app/online-store', ($this->save)(['show_found_pictures' => '1']))
        ->assertSessionHasNoErrors()->assertRedirect('/app/online-store');
    expect(($this->stored)())->toBeTrue();

    $audit = AuditLog::query()->where('action', 'online_store.updated')->latest('id')->first();
    expect($audit->before['show_found_pictures'])->toBeFalse()->and($audit->after['show_found_pictures'])->toBeTrue();

    $this->actingAs($this->owner)->get('/app/online-store')->assertInertia(fn (Assert $p) => $p->where('store.showFoundPictures', true));

    // Left out: kept. Sent as off: off.
    $this->actingAs($this->owner)->post('/app/online-store', ($this->save)(['headline' => 'Fresh today']))->assertSessionHasNoErrors();
    expect(($this->stored)())->toBeTrue();
    $this->actingAs($this->owner)->post('/app/online-store', ($this->save)(['show_found_pictures' => '0']))->assertSessionHasNoErrors();
    expect(($this->stored)())->toBeFalse();

    $this->actingAs($this->owner)->post('/app/online-store', ($this->save)(['show_found_pictures' => 'maybe']))->assertSessionHasErrors('show_found_pictures');
});

test('only store.manage may change it (staff and accountants 403, guests to the login page)', function () {
    $this->post('/app/online-store', ($this->save)(['show_found_pictures' => '1']))->assertRedirect('/login');

    foreach ([CompanyRole::Staff, CompanyRole::Accountant] as $role) {
        $this->actingAs(F::member($this->f->company, $role))->postJson('/app/online-store', ($this->save)(['show_found_pictures' => '1']))->assertForbidden();
    }

    expect(($this->stored)())->toBeFalse();
});

test('a business cannot change another business\'s store: the save changes only its own', function () {
    $other = OnlineStoreFixtures::make('Stoney Mini Mart', 'stoney');
    $theirs = F::member($other->company, CompanyRole::Owner);

    $this->actingAs($theirs)->post('/app/online-store', ($this->save)(['slug' => 'stoney', 'show_found_pictures' => '1']))->assertSessionHasNoErrors();
    expect(($this->stored)($other))->toBeTrue()->and(($this->stored)())->toBeFalse();

    // Using our slug does not reach our store: it is taken.
    $this->actingAs($theirs)->post('/app/online-store', ($this->save)(['slug' => 'kirkgate', 'show_found_pictures' => '0']))->assertSessionHasErrors('slug');
    expect(($this->stored)())->toBeFalse()->and(($this->stored)($other))->toBeTrue();
});

test('with TILL_PRODUCT_IMAGES off the switch is hidden and a sent value is ignored', function () {
    config(['sync.product_images.enabled' => false]);

    $this->actingAs($this->owner)->get('/app/online-store')->assertOk()->assertInertia(fn (Assert $p) => $p->where('tillPictures', false));
    $this->actingAs($this->owner)->post('/app/online-store', ($this->save)(['show_found_pictures' => '1']))->assertSessionHasNoErrors();

    expect(($this->stored)())->toBeFalse();
});
