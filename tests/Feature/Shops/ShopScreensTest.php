<?php

use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Mail\Mailables\AdminTillRequestMail;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;
use Tests\Feature\TillData\TillFixtures;

uses(TenancyTestHelpers::class);

/** Module 4.7: Shops and tills screens on the tenant portal (shops.view / shops.manage / business.manage). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-10-25 10:00:00');
    $this->licence = Licence::factory()->forRegister($this->sync->tills[TillFixtures::TILL_1])->paid(30)->create();
    Licence::factory()->forRegister($this->sync->tills[TillFixtures::BRADFORD_TILL])->trial()->create();
    $this->owner = $this->memberOf($this->company, CompanyRole::Owner);
    $this->manager = $this->memberOf($this->company, CompanyRole::Manager);
    $this->leedsId = $this->sync->leeds->id;
    $this->bradfordId = $this->sync->bradford->id;
    $this->shopForm = ['name' => 'Leeds Market', 'address' => '2 Kirkgate', 'town' => 'Leeds', 'postcode' => 'ls1 6ab', 'phone' => '0113 496 0000', 'vat_number' => '', 'receipt_footer' => 'Thank you'];
    $this->businessForm = ['name' => 'Kirkgate Group', 'legal_name' => 'Kirkgate Stores Ltd', 'vat_number' => '123456789', 'company_number' => '', 'address' => '', 'town' => '', 'postcode' => '', 'phone' => '', 'email' => 'Hello@Kirkgate.test', 'receipt_footer' => ''];
    $this->ask = ['kind' => 'moreTills', 'branch_id' => $this->leedsId, 'tills' => 1, 'message' => 'Lottery counter'];
    Mail::fake();
});

test('guests are sent to the login page', function () {
    foreach (['/app/shops', '/app/shops/business', "/app/shops/{$this->leedsId}"] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    $this->put("/app/shops/{$this->leedsId}", $this->shopForm)->assertRedirect('/login');
    $this->put('/app/shops/business', $this->businessForm)->assertRedirect('/login');
    $this->post('/app/shops/requests', $this->ask)->assertRedirect('/login');
});

test('staff get 403; accountants read only; managers edit shops but not the business', function () {
    $staff = $this->memberOf($this->company, CompanyRole::Staff);
    $accountant = $this->memberOf($this->company, CompanyRole::Accountant);

    foreach (['/app/shops', '/app/shops/business', "/app/shops/{$this->leedsId}"] as $url) {
        $this->actingAs($staff)->get($url)->assertForbidden();
        $this->actingAs($accountant)->get($url)->assertOk();
    }
    foreach ([$staff, $accountant] as $user) {
        $this->actingAs($user)->put("/app/shops/{$this->leedsId}", $this->shopForm)->assertForbidden();
        $this->actingAs($user)->put('/app/shops/business', $this->businessForm)->assertForbidden();
        $this->actingAs($user)->post('/app/shops/requests', $this->ask)->assertForbidden();
    }
    $this->actingAs($this->manager)->put('/app/shops/business', $this->businessForm)->assertForbidden();
    $this->actingAs($this->manager)->get('/app/shops/business')->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit', false));

    expect($this->sync->leeds->fresh()->name)->not->toBe('Leeds Market')
        ->and($this->company->fresh()->name)->not->toBe('Kirkgate Group')
        ->and(LicenceAlert::withoutCompanyScope()->count())->toBe(0);
});

test('the list shows every shop with licence summary and health, and never a licence key', function () {
    $response = $this->actingAs($this->owner)->get('/app/shops')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/shops/index')
        ->has('shops', 2)
        ->where('summary.shops', 2)->where('summary.tills', 3)
        ->where('can.manage', true)->where('can.manageBusiness', true)->where('restricted', false)
        ->where('requestOptions.canAskForShop', true)
        ->where('shops.0.tillsAllowed', 1));

    $response->assertDontSee($this->licence->key_hash);

    $show = $this->actingAs($this->owner)->get("/app/shops/{$this->leedsId}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/shops/show')
        ->has('tills', 2)
        ->where('tills.0.licence.maskedKey', $this->licence->maskedKey())
        ->where('tills.0.licence.status', 'active')
        ->where('tills.1.licence', null)
        ->where('can.edit', true));

    $json = json_encode($show->viewData('page'));
    expect($json)->not->toContain($this->licence->key_hash)
        ->and($json)->not->toContain('keyHash')
        ->and($json)->not->toContain('key_hash')
        ->and($json)->not->toContain('token_sha256');
});

test('the owner saves shop and business details; the manager saves a shop', function () {
    $this->actingAs($this->manager)->put("/app/shops/{$this->leedsId}", $this->shopForm)->assertRedirect()->assertSessionHas('success');
    $leeds = $this->sync->leeds->fresh();
    expect([$leeds->name, $leeds->postcode, $leeds->vat_number, $leeds->code])->toBe(['Leeds Market', 'LS1 6AB', null, 'LDS']);

    $this->actingAs($this->owner)->put("/app/shops/{$this->leedsId}", [...$this->shopForm, 'name' => '', 'vat_number' => 'nope'])->assertSessionHasErrors(['name', 'vat_number']);
    $this->actingAs($this->owner)->put("/app/shops/{$this->leedsId}", [...$this->shopForm, 'code' => 'ZZZ', 'is_active' => false])->assertRedirect();
    expect($this->sync->leeds->fresh()->only(['code', 'is_active']))->toBe(['code' => 'LDS', 'is_active' => true]);

    $this->actingAs($this->owner)->put('/app/shops/business', [...$this->businessForm, 'notes' => 'x', 'max_branches' => 99])->assertRedirect()->assertSessionHas('success');
    $company = $this->company->fresh();
    expect([$company->name, $company->vat_number, $company->email, $company->notes, $company->max_branches])
        ->toBe(['Kirkgate Group', 'GB123456789', 'hello@kirkgate.test', null, $this->company->max_branches]);
});

test('ask for more tills from the portal: admin request raised, staff emailed, listed back', function () {
    $this->actingAs($this->manager)->post('/app/shops/requests', $this->ask)->assertRedirect()->assertSessionHas('success');
    $this->actingAs($this->manager)->post('/app/shops/requests', [...$this->ask, 'branch_id' => null])->assertSessionHasErrors('branch_id');
    $this->actingAs($this->manager)->post('/app/shops/requests', ['kind' => 'newShop', 'tills' => 2])->assertSessionHasErrors('new_shop_name');

    expect(LicenceAlert::withoutCompanyScope()->sole()->licence_id)->toBe($this->licence->id);
    Mail::assertQueued(AdminTillRequestMail::class, 1);

    $this->actingAs($this->owner)->get('/app/shops')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('requests', 1)->where('requests.0.kind', 'moreTills')->where('requests.0.tills', 1)->where('requests.0.done', false)
        ->where('requests.0.shop.id', $this->leedsId)->where('requests.0.message', 'Lottery counter'));
});

test('one-shop users see and edit only their shop, read the business and ask only for their shop', function () {
    $this->company->users()->updateExistingPivot($this->manager->id, ['branch_id' => $this->leedsId]);

    $this->actingAs($this->manager)->get('/app/shops')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('shops', 1)->where('shops.0.id', $this->leedsId)->where('restricted', true)
        ->where('requestOptions.canAskForShop', false)->where('can.manageBusiness', false));
    $this->actingAs($this->manager)->get("/app/shops/{$this->bradfordId}")->assertForbidden();
    $this->actingAs($this->manager)->put("/app/shops/{$this->bradfordId}", $this->shopForm)->assertForbidden();
    $this->actingAs($this->manager)->get('/app/shops/business')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('can.edit', false));
    $this->actingAs($this->manager)->put('/app/shops/business', $this->businessForm)->assertForbidden();
    $this->actingAs($this->manager)->post('/app/shops/requests', [...$this->ask, 'branch_id' => $this->bradfordId])->assertForbidden();
    $this->actingAs($this->manager)->post('/app/shops/requests', ['kind' => 'newShop', 'tills' => 1, 'new_shop_name' => 'York'])->assertForbidden();

    $this->actingAs($this->manager)->put("/app/shops/{$this->leedsId}", $this->shopForm)->assertRedirect();
    $this->actingAs($this->manager)->post('/app/shops/requests', $this->ask)->assertRedirect()->assertSessionHas('success');
    expect($this->sync->leeds->fresh()->name)->toBe('Leeds Market')
        ->and($this->sync->bradford->fresh()->name)->not->toBe('Leeds Market')
        ->and(LicenceAlert::withoutCompanyScope()->count())->toBe(1);
});

test('tenant isolation: another business\'s shop is a 404 and its requests are never listed', function () {
    $other = Company::factory()->create();
    $theirs = app(CurrentCompany::class)->runAs($other, fn () => Branch::factory()->forCompany($other)->create(['name' => 'Their shop']));
    $otherOwner = $this->memberOf($other, CompanyRole::Owner);
    $this->actingAs($this->owner)->post('/app/shops/requests', $this->ask)->assertRedirect();

    $this->actingAs($this->owner)->get("/app/shops/{$theirs->id}")->assertNotFound();
    $this->actingAs($this->owner)->put("/app/shops/{$theirs->id}", $this->shopForm)->assertNotFound();
    $this->actingAs($this->owner)->post('/app/shops/requests', [...$this->ask, 'branch_id' => $theirs->id])->assertSessionHasErrors('branch_id');
    $this->actingAs($otherOwner)->get('/app/shops')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('shops', 1)->where('shops.0.name', 'Their shop')->has('requests', 0));

    expect($theirs->fresh()->name)->toBe('Their shop');
});
