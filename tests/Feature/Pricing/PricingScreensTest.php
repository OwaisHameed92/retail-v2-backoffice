<?php

use App\Domain\Pricing\Actions\SetShopPrice;
use App\Domain\Promotions\Actions\SavePromotion;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\PromotionRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;
use Tests\Feature\TillData\TillFixtures;

uses(TenancyTestHelpers::class);

/** Module 4.3: the prices and promotions screens: access, one-shop rules, isolation, and the writes they make. */
beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->product = PricingFixtures::product($this->company, CatalogueFixtures::seed($this->company));
    $this->as = fn (Closure $fn) => app(CurrentCompany::class)->runAs($this->company, $fn);
    $this->scheduled = ($this->as)(fn () => app(SetShopPrice::class)->handle($this->sync->bradford, $this->product, null, '0.99', CarbonImmutable::parse('2026-11-01', 'UTC')));
    $this->everyShopOffer = ($this->as)(fn () => app(SavePromotion::class)->handle(null, Arr::except(PricingFixtures::offer($this->product->id), ['items'])));
    $this->manager = $this->memberOf($this->company, CompanyRole::Manager);
    $this->oneShop = $this->memberOf($this->company, CompanyRole::Manager);
    DB::table('company_user')->where('user_id', $this->oneShop->id)->update(['branch_id' => $this->sync->leeds->id]);
});

/** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function pricingWrites(object $t, ?string $shop = null): array
{
    $p = $t->product->id;

    return [
        ['post', "/app/prices/{$p}/shop", ['branch_id' => $shop ?? $t->sync->leeds->id, 'price' => '1.30']],
        ['post', "/app/prices/{$p}/shop/end", ['branch_id' => $shop ?? $t->sync->leeds->id]],
        ['post', "/app/prices/{$p}/every-shop", ['price' => '1.60', 'end_shop_ids' => []]],
        ['post', "/app/prices/rows/{$t->scheduled->id}/cancel", []],
        ['post', '/app/promotions', PricingFixtures::offer($p, ['branch_id' => $shop])],
        ['put', "/app/promotions/{$t->everyShopOffer->id}", PricingFixtures::offer($p, ['branch_id' => $shop])],
        ['post', "/app/promotions/{$t->everyShopOffer->id}/end", []],
    ];
}

test('guests are sent to the login page', function () {
    foreach (['/app/prices', '/app/prices/changes', "/app/prices/{$this->product->id}", '/app/promotions', '/app/promotions/create', "/app/promotions/{$this->everyShopOffer->id}"] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    foreach (pricingWrites($this) as [$method, $url, $data]) {
        $this->{$method}($url, $data)->assertRedirect('/login');
    }
});

test('accountants get 403 everywhere; staff may look but not change anything', function () {
    $accountant = $this->memberOf($this->company, CompanyRole::Accountant);
    $staff = $this->memberOf($this->company, CompanyRole::Staff);

    $this->actingAs($accountant)->get('/app/prices')->assertForbidden();
    $this->actingAs($accountant)->get('/app/promotions')->assertForbidden();
    $this->actingAs($staff)->get("/app/prices/{$this->product->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->component('app/prices/show')->where('canSetShopPrices', false)->where('canSetEveryShop', false));
    $this->actingAs($staff)->get('/app/promotions')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canCreate', false)->where('promotions.data.0.canEdit', false));
    $this->actingAs($staff)->get('/app/promotions/create')->assertForbidden();

    foreach ([$accountant, $staff] as $user) {
        foreach (pricingWrites($this) as [$method, $url, $data]) {
            $this->actingAs($user)->{$method}($url, $data)->assertForbidden();
        }
    }

    expect(BranchPrice::withoutCompanyScope()->count())->toBe(1)->and(DB::table('products')->value('sell_price'))->toEqual('1.45')
        ->and(PromotionRule::withoutCompanyScope()->count())->toBe(1);
});

test('a manager of every shop sees every shop, sets and ends shop prices and moves the business price', function () {
    $this->actingAs($this->manager)->get('/app/prices')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->component('app/prices/index')->has('shops', 2)->where('products.data.0.sellPrice', '1.45')->where('products.data.0.scheduled', 1)
        ->where('counts.scheduled', 1));

    $p = $this->product->id;
    $this->actingAs($this->manager)->post("/app/prices/{$p}/shop", ['branch_id' => $this->sync->leeds->id, 'price' => '1.3'])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post("/app/prices/{$p}/shop", ['branch_id' => $this->sync->leeds->id, 'price' => '1.999'])->assertSessionHasErrors('price');
    $this->actingAs($this->manager)->get("/app/prices/{$p}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shops.1.current.price', '1.30')->where('shops.0.scheduled.0.price', '0.99')->has('history', 2)->where('canSetEveryShop', true));

    $this->actingAs($this->manager)->post("/app/prices/{$p}/every-shop", ['price' => '1.55', 'end_shop_ids' => [$this->sync->leeds->id]])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post("/app/prices/rows/{$this->scheduled->id}/cancel")->assertSessionHasNoErrors();

    expect(DB::table('products')->value('sell_price'))->toEqual('1.55')
        ->and(BranchPrice::withoutCompanyScope()->whereNull('valid_to_utc')->count())->toBe(0);

    $this->actingAs($this->manager)->get('/app/prices/changes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('app/prices/changes')->where('preview', null));
});

test('a one-shop manager prices and offers only their own shop; everything company-wide is read-only', function () {
    $p = $this->product->id;
    $this->actingAs($this->oneShop)->get('/app/prices')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('shops', 1)->where('shops.0.id', $this->sync->leeds->id)->where('restrictedShop', $this->sync->leeds->id));
    $this->actingAs($this->oneShop)->get("/app/prices/{$p}")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('shops', 1)->has('history', 0)->where('canSetShopPrices', true)->where('canSetEveryShop', false));

    foreach (pricingWrites($this, $this->sync->bradford->id) as [$method, $url, $data]) {
        $this->actingAs($this->oneShop)->{$method}($url, $data)->assertForbidden();
    }
    $this->actingAs($this->oneShop)->post('/app/promotions', PricingFixtures::offer($p))->assertForbidden();

    $this->actingAs($this->oneShop)->post("/app/prices/{$p}/shop", ['branch_id' => $this->sync->leeds->id, 'price' => '1.25'])->assertSessionHasNoErrors();
    $this->actingAs($this->oneShop)->post('/app/promotions', PricingFixtures::offer($p, ['name' => 'Leeds only', 'branch_id' => $this->sync->leeds->id]))
        ->assertSessionHasNoErrors()->assertRedirect();
    $mine = PromotionRule::withoutCompanyScope()->where('name', 'Leeds only')->sole();
    $this->actingAs($this->oneShop)->put("/app/promotions/{$mine->id}", PricingFixtures::offer($p, ['name' => 'Leeds only', 'branch_id' => $this->sync->leeds->id, 'percent' => '15']))->assertSessionHasNoErrors();
    $this->actingAs($this->oneShop)->put("/app/promotions/{$mine->id}", PricingFixtures::offer($p, ['name' => 'Leeds only', 'branch_id' => null]))->assertForbidden();
    $this->actingAs($this->oneShop)->post("/app/promotions/{$mine->id}/end")->assertSessionHasNoErrors();

    $this->actingAs($this->oneShop)->get('/app/promotions')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('promotions.data', 2)->where('promotions.data.0.canEdit', true)->where('promotions.data.1.canEdit', false));
    $this->actingAs($this->oneShop)->get("/app/promotions/{$this->everyShopOffer->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', false));

    expect(BranchPrice::withoutCompanyScope()->where('branch_id', $this->sync->leeds->id)->sole()->price)->toBe('1.25')
        ->and(DB::table('products')->value('sell_price'))->toEqual('1.45')
        ->and($mine->fresh()->percent)->toBe('15.0000')->and($mine->fresh()->is_active)->toBeFalse()
        ->and($this->everyShopOffer->fresh()->is_active)->toBeTrue();
});

test('a manager creates, edits and ends an offer through the screens', function () {
    $this->actingAs($this->manager)->get('/app/promotions/create')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->component('app/promotions/form')->where('promotion', null)->has('options.shops', 2));
    $this->actingAs($this->manager)->post('/app/promotions', PricingFixtures::offer($this->product->id, ['name' => '3 for £2', 'type' => 'multiBuy', 'buy_quantity' => '3', 'deal_price' => '2']))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post('/app/promotions', PricingFixtures::offer($this->product->id, ['type' => 'fixedOff']))->assertSessionHasErrors('amount_off');

    $rule = PromotionRule::withoutCompanyScope()->where('name', '3 for £2')->sole();
    $this->actingAs($this->manager)->get("/app/promotions/{$rule->id}")->assertInertia(fn (AssertableInertia $p) => $p
        ->where('promotion.deal', '3 for £2.00')->where('promotion.status', 'live')->where('canEdit', true));
    $this->actingAs($this->manager)->get('/app/promotions?status=live')->assertInertia(fn (AssertableInertia $p) => $p->has('promotions.data', 2)->where('counts.live', 2));
    $this->actingAs($this->manager)->post("/app/promotions/{$rule->id}/end")->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->get('/app/promotions?status=ended')->assertInertia(fn (AssertableInertia $p) => $p->has('promotions.data', 1)->where('promotions.data.0.id', $rule->id));
});

test('another business\'s products, prices and offers are not found and cannot be changed', function () {
    $other = Company::factory()->create();
    $stranger = $this->memberOf($other, CompanyRole::Owner);
    $p = $this->product->id;

    $this->actingAs($stranger)->get("/app/prices/{$p}")->assertNotFound();
    $this->actingAs($stranger)->get("/app/promotions/{$this->everyShopOffer->id}")->assertNotFound();
    $this->actingAs($stranger)->post("/app/prices/{$p}/every-shop", ['price' => '9.99', 'end_shop_ids' => []])->assertNotFound();
    $this->actingAs($stranger)->post("/app/prices/{$p}/shop", ['branch_id' => $this->sync->leeds->id, 'price' => '9.99'])->assertNotFound();
    $this->actingAs($stranger)->post("/app/prices/rows/{$this->scheduled->id}/cancel")->assertNotFound();
    $this->actingAs($stranger)->put("/app/promotions/{$this->everyShopOffer->id}", PricingFixtures::offer($p))->assertNotFound();
    $this->actingAs($stranger)->post("/app/promotions/{$this->everyShopOffer->id}/end")->assertNotFound();
    $this->actingAs($stranger)->get('/app/prices')->assertInertia(fn (AssertableInertia $page) => $page->has('products.data', 0)->has('shops', 0));
    $this->actingAs($stranger)->get('/app/promotions')->assertInertia(fn (AssertableInertia $page) => $page->has('promotions.data', 0));

    expect(DB::table('products')->value('sell_price'))->toEqual('1.45')->and(BranchPrice::withoutCompanyScope()->count())->toBe(1)
        ->and($this->everyShopOffer->fresh()->is_active)->toBeTrue();
});

test('price change batches from a till are listed read-only with a preview; a one-shop user sees only their shop\'s', function () {
    $batch = Pull::payload('PriceChangeBatch', '01K5W2B9J000000000PB000201', ['branchId' => TillFixtures::BRADFORD, 'name' => 'Autumn prices', 'status' => 'live']);
    $line = Pull::payload('PriceChangeLine', '01K5W2B9J000000000PC000201', [
        'branchId' => TillFixtures::BRADFORD, 'batchId' => '01K5W2B9J000000000PB000201', 'productId' => $this->product->id,
        'productName' => 'Toastie White 800g', 'oldPrice' => 1.45, 'newPrice' => 1.35,
    ]);
    $this->sync->push([
        TillFixtures::envelope('PriceChangeBatch', $batch, 1, ['branchId' => TillFixtures::BRADFORD]),
        TillFixtures::envelope('PriceChangeLine', $line, 2, ['branchId' => TillFixtures::BRADFORD]),
    ], bradford: true)->assertOk()->assertJsonPath('accepted', 2);

    $this->actingAs($this->manager)->get('/app/prices/changes?batch=01K5W2B9J000000000PB000201')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->where('batches.data.0.name', 'Autumn prices')->where('batches.data.0.shop', 'Bradford')->where('batches.data.0.lines', 1)
        ->where('preview.lines.0.newPrice', '1.35')->where('preview.lines.0.businessPriceNow', '1.45'));
    $this->actingAs($this->oneShop)->get('/app/prices/changes?batch=01K5W2B9J000000000PB000201')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->has('batches.data', 0)->where('preview', null));
});
