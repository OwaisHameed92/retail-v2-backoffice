<?php

use App\Domain\OnlineStore\Actions\PlaceCollectOrder;
use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use App\Domain\OnlineStore\Actions\SetStoreLive;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Queries\CollectionSlots;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\OnlineStore\Queries\StorefrontShops;
use App\Domain\OnlineStore\Queries\StoreOrderById;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->a = OnlineStoreFixtures::make('Kirkgate Convenience', 'kirkgate');
    $this->b = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $this->aBeans = $this->a->product('A beans');
    $this->bSteak = $this->b->product('B steak');
    $this->find = fn (string $slug) => app(FindStoreBySlug::class)->handle($slug);
    $this->slot = '2026-11-02T10:00:00Z';
});

test('a slug finds only its own business\'s store, with no current company needed', function () {
    $a = ($this->find)('kirkgate');
    $b = ($this->find)('BRIGGATE');

    expect(app(CurrentCompany::class)->has())->toBeFalse()
        ->and($a?->id)->toBe($this->a->store->id)->and($a?->company->id)->toBe($this->a->company->id)
        ->and($b?->id)->toBe($this->b->store->id)->and($b?->company->id)->toBe($this->b->company->id)
        ->and(($this->find)('nobody'))->toBeNull()
        ->and(($this->find)('../kirkgate'))->toBeNull();
});

test('store not found: switch off or Pakistan', function () {
    config(['online_store.enabled' => false]);
    expect(($this->find)('kirkgate'))->toBeNull();

    config(['online_store.enabled' => true]);
    OnlineStoreFixtures::asPakistan();
    expect(($this->find)('kirkgate'))->toBeNull();
})->group('country-pk');

test('store not found when off line or the business is suspended or cancelled', function () {
    app(SetStoreLive::class)->handle($this->a->company, false);
    expect(($this->find)('kirkgate'))->toBeNull();

    app(SetStoreLive::class)->handle($this->a->company, true);
    expect(($this->find)('kirkgate'))->not->toBeNull();

    $this->b->company->forceFill(['status' => CompanyStatus::Suspended])->save();
    expect(($this->find)('briggate'))->toBeNull();

    $this->b->company->forceFill(['status' => CompanyStatus::Cancelled])->save();
    expect(($this->find)('briggate'))->toBeNull();
});

test('store A never lists, shows or counts business B\'s products', function () {
    $catalogue = app(StorefrontCatalogue::class);

    expect(collect($catalogue->products($this->a->store, $this->a->shop)->items)->pluck('id')->all())->toBe([$this->aBeans])
        ->and($catalogue->products($this->a->store, $this->a->shop, 'steak')->total)->toBe(0)
        ->and($catalogue->product($this->a->store, $this->a->shop, $this->bSteak))->toBeNull()
        ->and($catalogue->items($this->a->store, $this->a->shop, [$this->aBeans, $this->bSteak]))->toHaveKeys([$this->aBeans])->toHaveCount(1)
        ->and(collect($catalogue->departments($this->a->store, $this->a->shop))->pluck('count')->all())->toBe([1]);
});

test('store A with business B\'s shop shows nothing', function () {
    $catalogue = app(StorefrontCatalogue::class);

    expect($catalogue->products($this->a->store, $this->b->shop)->total)->toBe(0)
        ->and($catalogue->items($this->a->store, $this->b->shop, [$this->bSteak]))->toBe([])
        ->and($catalogue->departments($this->a->store, $this->b->shop))->toBe([])
        ->and(app(StorefrontShops::class)->find($this->a->store, $this->b->shop->id))->toBeNull()
        ->and(app(StorefrontShops::class)->handle($this->a->store)->pluck('id')->all())->toBe([$this->a->shop->id]);
});

test('business B\'s till rows never change store A\'s prices, stock or what it shows', function () {
    // Rows of business B that carry A's product and shop ids (as a bad push might): A must ignore them all.
    $b = ['company_id' => $this->b->company->id];
    DB::table('branch_prices')->insert(['id' => Ulid::new(), ...$b, 'branch_id' => $this->a->leeds->id, 'product_id' => $this->aBeans, 'price' => '0.01', 'valid_from_utc' => '2026-01-01 00:00:00', 'row_version' => 1]);
    DB::table('branch_products')->insert(['id' => Ulid::new(), ...$b, 'branch_id' => $this->a->leeds->id, 'product_id' => $this->aBeans, 'is_active' => false, 'is_stock_tracked' => true, 'qty_on_hand' => '0', 'row_version' => 1]);
    DB::table('medicine_classifications')->insert(['id' => Ulid::new(), ...$b, 'product_id' => $this->aBeans, 'class' => 'prescriptionOnly', 'note' => '', 'row_version' => 1]);
    DB::table('online_store_products')->insert(['id' => Ulid::new(), ...$b, 'product_id' => $this->aBeans, 'hidden' => true, 'created_at' => now(), 'updated_at' => now()]);

    $item = app(StorefrontCatalogue::class)->product($this->a->store, $this->a->shop, $this->aBeans);

    expect($item?->price)->toBe('1.00')->and($item?->orderable)->toBeTrue();
});

test('store A never accepts business B\'s products or shops', function () {
    $error = function (array $lines, ?string $shopId = null): array {
        try {
            app(PlaceCollectOrder::class)->handle($this->a->store, $this->a->input($lines, $this->slot, $shopId === null ? [] : ['shopId' => $shopId]));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        throw new RuntimeException('Accepted.');
    };

    expect($error([['productId' => $this->bSteak, 'quantity' => '1']]))->toHaveKey('lines.0.productId')
        ->and($error([['productId' => $this->aBeans, 'quantity' => '1']], $this->b->shop->id))->toHaveKey('shopId')
        ->and(OnlineOrder::withoutCompanyScope()->count())->toBe(0);
});

test('orders, slot counts and order pages never cross businesses', function () {
    $mine = $this->a->order([['productId' => $this->aBeans, 'quantity' => '1']], $this->slot);
    $theirs = $this->b->order([['productId' => $this->bSteak, 'quantity' => '1']], $this->slot);
    $this->b->order([['productId' => $this->bSteak, 'quantity' => '1']], $this->slot);

    $slot = app(CollectionSlots::class)->find($this->a->shop, CarbonImmutable::parse($this->slot));

    expect($slot?->taken)->toBe(1)
        ->and($mine->order->company_id)->toBe($this->a->company->id)
        ->and($mine->order->lines->pluck('company_id')->unique()->all())->toBe([$this->a->company->id])
        ->and(app(StoreOrderById::class)->handle($this->a->store, $theirs->order->id))->toBeNull()
        ->and(app(StoreOrderById::class)->handle($this->b->store, $theirs->order->id)?->id)->toBe($theirs->order->id)
        ->and(app(CurrentCompany::class)->runAs($this->a->company, fn () => OnlineOrder::query()->pluck('id')->all()))->toBe([$mine->order->id]);
});

test('portal actions never touch another business\'s product', function () {
    expect(fn () => app(SetOnlineProduct::class)->handle($this->a->company, $this->bSteak, ['hidden' => true]))->toThrow(ValidationException::class);
});
