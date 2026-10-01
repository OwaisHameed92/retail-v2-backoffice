<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Models\NewsTitle;
use App\Domain\TillData\Sync\BranchDepartures;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.8: news titles on the portal (hub-owned, per shop or every shop): who may see and change them, the
 * one-shop rule, and that each change reaches exactly the right shops' tills (a move gives the old shop a `D`).
 */
beforeEach(function () {
    $this->travelTo('2026-09-30 10:00:00');
    $this->sync = new SyncApiFixtures($this);
    [$this->company, $this->leeds, $this->bradford] = [$this->sync->company, $this->sync->leeds, $this->sync->bradford];
    F::catalogue($this->company);
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $this->input = fn (array $overrides = []) => [
        'name' => 'The Guardian', 'publisher' => 'Guardian Media Group', 'frequency' => 'daily', 'supplier_id' => F::SUPPLIER,
        'cover_price' => '2.80', 'linked_product_id' => '', 'linked_barcode' => '977026130700', 'branch_id' => '', ...$overrides,
    ];
    $this->titles = function (bool $bradford = false): array {
        $reply = $this->sync->pull(0, bradford: $bradford)->assertOk();
        expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);

        return array_values(array_filter(Pull::changes($reply), fn (array $c) => $c['entity'] === 'NewsTitle'));
    };
    $this->create = fn (array $overrides = [], $user = null) => $this->actingAs($user ?? $this->owner)->post('/app/news/titles', ($this->input)($overrides));
});

test('guests go to the login page; staff get 403; accountants may look but not change titles', function () {
    $title = Pull::portalCreate($this->company, 'NewsTitle', Pull::payload('NewsTitle', '01K5T0Q8C40000000000NT0001', ['isActive' => true]), ['branch_id' => null]);
    $reads = ['/app/news/summary', '/app/news/titles', '/app/news/deliveries', '/app/news/returns', '/app/news/vouchers'];
    $writes = ['/app/news/titles/create', "/app/news/titles/{$title->id}/edit"];

    foreach ([...$reads, ...$writes] as $url) {
        $this->get($url)->assertRedirect('/login');
    }

    $staff = F::member($this->company, CompanyRole::Staff);
    foreach ([...$reads, ...$writes] as $url) {
        $this->actingAs($staff)->get($url)->assertForbidden();
    }
    $this->actingAs($staff)->post('/app/news/titles', ($this->input)())->assertForbidden();

    $accountant = F::member($this->company, CompanyRole::Accountant);
    $this->actingAs($accountant)->get('/app/news')->assertRedirect('/app/news/summary');
    foreach ($reads as $url) {
        $this->actingAs($accountant)->get($url)->assertSuccessful();
    }
    foreach ($writes as $url) {
        $this->actingAs($accountant)->get($url)->assertForbidden();
    }
    $this->actingAs($accountant)->post('/app/news/titles', ($this->input)())->assertForbidden();
    $this->actingAs($accountant)->post("/app/news/titles/{$title->id}/archive")->assertForbidden();
    $this->actingAs($accountant)->put("/app/news/titles/{$title->id}", ($this->input)())->assertForbidden();

    $manager = F::member($this->company, CompanyRole::Manager);
    $this->actingAs($manager)->get('/app/news/titles')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('app/news/index')->where('can', ['manage' => true, 'everyShop' => true]));
    $this->actingAs($manager)->get('/app/news/titles/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/news/title-form'));
});

test('a title for every shop reaches every till; an edit is pulled again with the next row version', function () {
    ($this->create)()->assertRedirect('/app/news/titles')->assertSessionHas('success');
    $title = NewsTitle::query()->withoutGlobalScopes()->sole();

    expect($title->branch_id)->toBeNull()->and($title->row_version)->toBe(1)->and($title->cover_price)->toBe('2.80');
    [$leeds] = ($this->titles)();
    [$bradford] = ($this->titles)(true);
    expect($leeds['payload'])->toMatchArray(['name' => 'The Guardian', 'publisher' => 'Guardian Media Group', 'frequency' => 'daily', 'branchId' => '',
        'supplierId' => F::SUPPLIER, 'coverPrice' => '2.80', 'linkedBarcode' => '977026130700', 'linkedProductId' => '', 'isActive' => true])
        ->and($leeds['branchId'])->toBe('')->and($bradford['entityId'])->toBe($title->id);

    $this->travel(1)->minutes();
    $this->actingAs($this->owner)->put("/app/news/titles/{$title->id}", ($this->input)(['cover_price' => '3.00']))->assertRedirect('/app/news/titles');
    [$leeds] = ($this->titles)();
    expect($leeds['payload'])->toMatchArray(['coverPrice' => '3.00', 'rowVersion' => 2])
        ->and(DB::table('audit_logs')->where('action', 'news_title.updated')->count())->toBe(1);

    // Saving the same values again writes nothing.
    $this->actingAs($this->owner)->put("/app/news/titles/{$title->id}", ($this->input)(['cover_price' => '3.00']))->assertRedirect();
    expect($title->fresh()->row_version)->toBe(2);
});

test('a shop\'s own title reaches only that shop; moving it gives the old shop(s) a D and the new one the row', function () {
    ($this->create)(['branch_id' => $this->leeds->id, 'name' => 'Yorkshire Evening Post'])->assertRedirect();
    $title = NewsTitle::query()->withoutGlobalScopes()->sole();

    expect(($this->titles)())->toHaveCount(1)->and(($this->titles)()[0]['branchId'])->toBe(TillFixtures::LEEDS)
        ->and(($this->titles)(true))->toBe([]);

    $this->travel(1)->minutes();
    $this->actingAs($this->owner)->put("/app/news/titles/{$title->id}", ($this->input)(['branch_id' => $this->bradford->id, 'name' => 'Yorkshire Evening Post']))
        ->assertRedirect()->assertSessionHas('success');

    [$leeds] = ($this->titles)();
    [$bradford] = ($this->titles)(true);
    expect($leeds)->toMatchArray(['op' => 'D', 'branchId' => TillFixtures::LEEDS, 'payload' => null])
        ->and($bradford)->toMatchArray(['op' => 'U', 'branchId' => TillFixtures::BRADFORD])
        ->and(DB::table('audit_logs')->where('action', 'news_title.moved')->count())->toBe(1);

    // From one shop to every shop: the departure is cleared, both shops get the row.
    $this->travel(1)->minutes();
    $this->actingAs($this->owner)->put("/app/news/titles/{$title->id}", ($this->input)(['branch_id' => '', 'name' => 'Yorkshire Evening Post']))->assertRedirect();
    expect(DB::table(BranchDepartures::TABLE)->count())->toBe(0)
        ->and(($this->titles)()[0]['op'])->toBe('U')->and(($this->titles)(true)[0]['op'])->toBe('U');

    // From every shop to Leeds only: Bradford gets its D.
    $this->travel(1)->minutes();
    $this->actingAs($this->owner)->put("/app/news/titles/{$title->id}", ($this->input)(['branch_id' => $this->leeds->id, 'name' => 'Yorkshire Evening Post']))->assertRedirect();
    expect(($this->titles)(true)[0])->toMatchArray(['op' => 'D', 'branchId' => TillFixtures::BRADFORD])
        ->and(($this->titles)()[0]['payload']['branchId'])->toBe(TillFixtures::LEEDS);
});

test('archive and restore send isActive to the tills; the list and its filters show them', function () {
    ($this->create)()->assertRedirect();
    $title = NewsTitle::query()->withoutGlobalScopes()->sole();

    $this->actingAs($this->owner)->post("/app/news/titles/{$title->id}/archive")->assertRedirect()->assertSessionHas('success');
    expect(($this->titles)()[0]['payload']['isActive'])->toBeFalse();
    $this->actingAs($this->owner)->post("/app/news/titles/{$title->id}/archive")->assertRedirect();
    expect($title->fresh()->row_version)->toBe(2);

    $props = $this->actingAs($this->owner)->get('/app/news/titles?status=archived')->assertOk()->viewData('page')['props'];
    expect($props['rows']['data'])->toHaveCount(1)->and($props['rows']['data'][0])->toMatchArray(['name' => 'The Guardian', 'status' => 'archived', 'shop' => null, 'canEdit' => true])
        ->and(collect($props['stats'])->pluck('value', 'label')->all())->toMatchArray(['On sale' => '0', 'Archived' => '1']);

    $this->actingAs($this->owner)->post("/app/news/titles/{$title->id}/restore")->assertRedirect();
    expect(($this->titles)()[0]['payload']['isActive'])->toBeTrue()->and($title->fresh()->row_version)->toBe(3);
});

test('checks: a name already on sale at that shop, another business\'s supplier or product, bad input', function () {
    $other = new SyncApiFixtures($this, mapTillIds: false);
    $foreignSupplier = Pull::portalCreate($other->company, 'Supplier', [...TillFixtures::sample('pull-reply.head-office.json')['changes'][0]['payload'], 'id' => '01K5T0Q8C4000000000000S099']);
    ($this->create)()->assertRedirect();
    ($this->create)(['branch_id' => $this->leeds->id])->assertSessionHasErrors(['name' => 'A title with this name is already on sale at this shop.']);
    ($this->create)(['name' => 'The Times', 'supplier_id' => $foreignSupplier->id])->assertSessionHasErrors('supplier_id');
    ($this->create)(['name' => 'The Times', 'branch_id' => $other->leeds->id])->assertSessionHasErrors('branch_id');
    ($this->create)(['name' => 'The Times', 'linked_product_id' => '01K5T0Q8C4000000000000P999'])->assertSessionHasErrors('linked_product_id');
    ($this->create)(['name' => '', 'cover_price' => '1.999', 'frequency' => 'monthly'])->assertSessionHasErrors(['name', 'cover_price', 'frequency']);

    // Linking a product fills the barcode from it when none is typed.
    ($this->create)(['name' => 'The Times', 'linked_product_id' => F::COLA, 'linked_barcode' => ''])->assertRedirect()->assertSessionHasNoErrors();
    $times = NewsTitle::query()->where('name', 'The Times')->sole();
    expect($times->linked_product_id)->toBe(F::COLA)->and($times->linked_barcode)->toBe((string) DB::table('product_barcodes')->where('product_id', F::COLA)->value('barcode'));
});

test('a one-shop manager keeps only their own shop\'s titles; every-shop titles are read only to them', function () {
    $manager = F::member($this->company, CompanyRole::Manager, $this->leeds);
    $every = Pull::portalCreate($this->company, 'NewsTitle', Pull::payload('NewsTitle', '01K5T0Q8C40000000000NT0001', ['name' => 'Daily Mail', 'isActive' => true]), ['branch_id' => null]);
    $bradford = Pull::portalCreate($this->company, 'NewsTitle', Pull::payload('NewsTitle', '01K5T0Q8C40000000000NT0002', ['name' => 'Telegraph & Argus', 'isActive' => true]), ['branch_id' => $this->bradford->id]);

    ($this->create)(['branch_id' => ''], $manager)->assertForbidden();
    ($this->create)(['branch_id' => $this->bradford->id], $manager)->assertForbidden();
    ($this->create)(['branch_id' => $this->leeds->id, 'name' => 'Yorkshire Post'], $manager)->assertRedirect()->assertSessionHasNoErrors();
    $own = NewsTitle::query()->where('name', 'Yorkshire Post')->sole();

    $this->actingAs($manager)->get("/app/news/titles/{$every->id}/edit")->assertForbidden();
    $this->actingAs($manager)->put("/app/news/titles/{$every->id}", ($this->input)(['branch_id' => $this->leeds->id]))->assertForbidden();
    $this->actingAs($manager)->post("/app/news/titles/{$every->id}/archive")->assertForbidden();
    $this->actingAs($manager)->get("/app/news/titles/{$bradford->id}/edit")->assertNotFound();
    $this->actingAs($manager)->post("/app/news/titles/{$bradford->id}/archive")->assertNotFound();
    // Their own title cannot be moved away or made every shop's.
    $this->actingAs($manager)->put("/app/news/titles/{$own->id}", ($this->input)(['branch_id' => $this->bradford->id, 'name' => 'Yorkshire Post']))->assertForbidden();
    $this->actingAs($manager)->put("/app/news/titles/{$own->id}", ($this->input)(['branch_id' => '', 'name' => 'Yorkshire Post']))->assertForbidden();
    $this->actingAs($manager)->put("/app/news/titles/{$own->id}", ($this->input)(['branch_id' => $this->leeds->id, 'name' => 'Yorkshire Post', 'cover_price' => '1.70']))->assertRedirect();
    expect($own->fresh()->cover_price)->toBe('1.70')->and($every->fresh()->branch_id)->toBeNull()->and($bradford->fresh()->branch_id)->toBe($this->bradford->id);

    $props = $this->actingAs($manager)->get('/app/news/titles?shop='.$this->bradford->id)->assertOk()->viewData('page')['props'];
    expect(collect($props['rows']['data'])->pluck('canEdit', 'name')->all())->toBe(['Daily Mail' => false, 'Yorkshire Post' => true])
        ->and($props['filters']['shop'])->toBe($this->leeds->id)->and($props['can'])->toBe(['manage' => true, 'everyShop' => false])
        ->and($props['shops'])->toHaveCount(1);
    $this->actingAs($manager)->get('/app/news/titles/create')->assertOk()->assertInertia(fn (Assert $page) => $page->where('defaultShopId', $this->leeds->id));
});

test('another business\'s titles are not found and never listed', function () {
    $other = new SyncApiFixtures($this, mapTillIds: false);
    $foreign = Pull::portalCreate($other->company, 'NewsTitle', Pull::payload('NewsTitle', '01K5T0Q8C40000000000NT0009', ['name' => 'Their Paper', 'isActive' => true]), ['branch_id' => null]);

    $this->actingAs($this->owner)->get("/app/news/titles/{$foreign->id}/edit")->assertNotFound();
    $this->actingAs($this->owner)->put("/app/news/titles/{$foreign->id}", ($this->input)())->assertNotFound();
    $this->actingAs($this->owner)->post("/app/news/titles/{$foreign->id}/archive")->assertNotFound();
    expect($this->actingAs($this->owner)->get('/app/news/titles')->viewData('page')['props']['rows']['data'])->toBe([])
        ->and(NewsTitle::query()->withoutGlobalScopes()->find($foreign->id)->is_active)->toBeTrue();
});

test('VAT comes from the linked product: a title without one is flagged "no VAT line on the till", the zero rate is suggested', function () {
    ($this->create)(['name' => 'The Guardian'])->assertRedirect();
    ($this->create)(['name' => 'The Times', 'linked_product_id' => F::COLA, 'linked_barcode' => ''])->assertRedirect();
    ($this->create)(['name' => 'The Sun', 'linked_product_id' => F::WATER, 'linked_barcode' => ''])->assertRedirect();

    $rows = collect($this->actingAs($this->owner)->get('/app/news/titles')->assertOk()->viewData('page')['props']['rows']['data'])->keyBy('name');
    expect($rows['The Guardian'])->toMatchArray(['noVatLine' => true, 'vatNotZero' => false, 'vat' => null])
        ->and($rows['The Times'])->toMatchArray(['noVatLine' => false, 'vatNotZero' => true])
        ->and($rows['The Sun'])->toMatchArray(['noVatLine' => false, 'vatNotZero' => false]);

    $times = NewsTitle::query()->withoutGlobalScopes()->where('name', 'The Times')->sole();
    $this->actingAs($this->owner)->get("/app/news/titles/{$times->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('zeroVatRate.name', 'Zero')->where('linkedProduct.zeroRated', false));
    $this->actingAs($this->owner)->get('/app/news/titles/create?q=Toastie')->assertInertia(fn (Assert $page) => $page
        ->where('linkedProduct', null)->where('results.0.zeroRated', true));
});
