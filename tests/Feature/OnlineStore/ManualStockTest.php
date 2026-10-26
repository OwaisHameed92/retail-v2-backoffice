<?php

use App\Domain\OnlineStore\Actions\ChangeOnlineOrderStatus;
use App\Domain\OnlineStore\Actions\ChangeOrderLine;
use App\Domain\OnlineStore\Actions\PlaceCollectOrder;
use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use App\Domain\OnlineStore\Data\CollectOrderInput;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\OnlineStore\Support\ManualStock;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\OnlineStore\OnlineStoreOnlyHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * OS-13: the shop's own stock ("In stock / Out of stock" and an optional count) is used only while a business sells
 * without tills; a business with a live till keeps the tills' stock whatever is stored. A completed order takes its
 * items off the count; a cancelled, not-collected or unavailable line never does. Never written to the till tables.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, OnlineStoreOnlyHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

test('a business with live tills keeps the tills’ stock: the shop’s own stock is ignored and not offered', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $f = OnlineStoreFixtures::make();
    $beans = $f->product('Beans', ['track_stock' => true]);
    $f->stock($f->leeds, $beans, '5');
    app(SetOnlineProduct::class)->handle($f->company, $beans, ['out_of_stock' => true, 'stock_count' => '0']);

    $item = app(StorefrontCatalogue::class)->product($f->store, $f->shop, $beans);
    expect(ManualStock::applies($f->company->id))->toBeFalse()
        ->and($item->orderable)->toBeTrue()->and($item->stockLimit)->toBe('5.0000');

    $owner = $this->addMember($f->company, CompanyRole::Owner);
    $this->actingAs($owner, 'web')->get('/app/online-store/products')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('manualStock', false));
});

test('cancelled, not collected and unavailable lines never take stock off; the count is validated', function () {
    $this->atLondon('2026-10-26 10:00');
    $this->setVat(true);
    config(['online_store.enabled' => true, 'online_store.checkout_challenge' => false, 'online_store.customer_limits' => false]);
    $this->standardPlan();
    $company = $this->webOnlyBusiness($this->webOnlyPlan());
    $shop = $this->webOnlyLive($company);
    $f = new OnlineStoreFixtures;
    $f->company = $company;
    $f->department = OnlineStoreFixtures::department($company, 'Deli', 1);
    $f->category = OnlineStoreFixtures::category($company, $f->department, 'Cheese');
    $cheese = $f->product('Cheese', ['track_stock' => true, 'sell_price' => '4.00']);
    $ham = $f->product('Ham', ['track_stock' => true, 'sell_price' => '3.00']);

    expect(fn () => app(SetOnlineProduct::class)->handle($company, $cheese, ['stock_count' => '-1']))->toThrow(ValidationException::class);
    app(SetOnlineProduct::class)->handle($company, $cheese, ['stock_count' => '5']);
    app(SetOnlineProduct::class)->handle($company, $ham, ['stock_count' => '5']);
    $store = app(FindStoreBySlug::class)->handle($this->storeOf($company)->slug);
    $order = fn () => app(PlaceCollectOrder::class)->handle($store, CollectOrderInput::fromArray([
        'shopId' => $shop->id, 'collectFrom' => '2026-10-26T14:00:00Z', 'customerName' => 'Asha Patel', 'customerMobile' => '07700 900123',
        'customerEmail' => 'asha@example.test', 'lines' => [['productId' => $cheese, 'quantity' => '2'], ['productId' => $ham, 'quantity' => '1']],
    ]))->order;
    $count = fn (string $id) => (float) OnlineStoreProduct::withoutCompanyScope()->where('product_id', $id)->value('stock_count');
    $move = fn (string $id, OnlineOrderStatus ...$to) => array_map(fn ($s) => app(ChangeOnlineOrderStatus::class)->handle($company, $id, $s, 'Shop closed early'), $to);

    $cancelled = $order();
    $move($cancelled->id, OnlineOrderStatus::Cancelled);
    $missed = $order();
    $move($missed->id, OnlineOrderStatus::Picking, OnlineOrderStatus::Ready, OnlineOrderStatus::NotCollected);
    expect([$count($cheese), $count($ham)])->toBe([5.0, 5.0]);

    $picked = $order();
    $line = $picked->lines->firstWhere('product_id', $ham);
    $move($picked->id, OnlineOrderStatus::Picking);
    app(ChangeOrderLine::class)->handle($company, $picked->id, $line->id, 'unavailable');
    $move($picked->id, OnlineOrderStatus::Ready, OnlineOrderStatus::Collected);

    expect([$count($cheese), $count($ham)])->toBe([3.0, 5.0])
        ->and(DB::table('branch_products')->where('company_id', $company->id)->count())->toBe(0);
});

test('the stock is per business: one business’s count never moves another’s', function () {
    $this->atLondon('2026-10-26 10:00');
    config(['online_store.enabled' => true]);
    $this->standardPlan();
    $plan = $this->webOnlyPlan();
    $a = $this->webOnlyBusiness($plan);
    $b = $this->webOnlyBusiness($plan, 'Marco Deli', 'marco@marcodeli.test');
    $productB = Ulid::new();
    DB::table('products')->insert(['id' => $productB, 'company_id' => $b->id, 'name' => 'Pasta', 'short_name' => 'Pasta', 'sku' => 'P1', 'unit_code' => 'PCS',
        'sell_price' => '1.00', 'cost_price' => '0.5000', 'track_stock' => true, 'is_active' => true, 'is_weighed' => false, 'row_version' => 1]);

    expect(fn () => app(SetOnlineProduct::class)->handle($a, $productB, ['stock_count' => '9']))->toThrow(ValidationException::class);
    app(SetOnlineProduct::class)->handle($b, $productB, ['stock_count' => '4']);
    expect(OnlineStoreProduct::withoutCompanyScope()->where('product_id', $productB)->sole()->company_id)->toBe($b->id);
});
