<?php

use App\Domain\OnlineStore\Actions\PlaceCollectOrder;
use App\Domain\OnlineStore\Actions\SaveStoreShop;
use App\Domain\OnlineStore\Actions\SetStoreLive;
use App\Domain\OnlineStore\Actions\SetTakingOrders;
use App\Domain\OnlineStore\Data\CollectOrderInput;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Events\OnlineOrderPlaced;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Queries\StoreOrderById;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

const ONLINE_ORDER_SLOT = '2026-11-02T10:00:00Z';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->beans = $this->f->product('Beans', ['sell_price' => '1.10']);
    $this->mince = $this->f->product('Lamb mince', ['sell_price' => '9.80', 'is_weighed' => true, 'unit_code' => 'kg', 'max_qty_per_sale' => 3]);
    $this->place = fn (array $lines, string $slot = ONLINE_ORDER_SLOT, array $overrides = []) => app(PlaceCollectOrder::class)->handle($this->f->store, $this->f->input($lines, $slot, $overrides));
    $this->error = function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        throw new RuntimeException('No validation error.');
    };
});

test('places an order: server prices, snapshot lines, W-0001, and no order token (the page link is signed over the id)', function () {
    Event::fake([OnlineOrderPlaced::class]);
    $this->f->shopPrice($this->f->leeds, $this->beans, '0.95');

    $placed = ($this->place)([['productId' => $this->beans, 'quantity' => '3'], ['productId' => $this->mince, 'quantity' => '1.25']]);
    $order = $placed->order;

    expect($order->reference)->toBe('W-0001')
        ->and($order->status)->toBe(OnlineOrderStatus::New)
        ->and($order->branch_id)->toBe($this->f->leeds->id)
        ->and($order->collect_from->toIso8601ZuluString())->toBe('2026-11-02T10:00:00Z')
        ->and($order->collect_to->toIso8601ZuluString())->toBe('2026-11-02T10:30:00Z')
        ->and($order->items_total)->toBe('15.10')
        ->and($order->customer_email)->toBe('asha@example.test')
        ->and(property_exists($placed, 'token'))->toBeFalse()
        ->and($order->token_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($order->toArray())->not->toHaveKey('token_hash')
        ->and($order->lines->map(fn (OnlineOrderLine $l) => [$l->name, $l->unit_price, $l->quantity, $l->is_weighed, $l->line_total])->all())->toBe([
            ['Beans', '0.95', '3.0000', false, '2.85'],
            ['Lamb mince', '9.80', '1.2500', true, '12.25'],
        ]);

    Event::assertDispatched(OnlineOrderPlaced::class, fn (OnlineOrderPlaced $e) => $e->order->is($order));
    expect(AuditLog::query()->where('action', 'online_order.placed')->where('company_id', $this->f->company->id)->count())->toBe(1);
});

test('prices sent by the customer are ignored', function () {
    $input = CollectOrderInput::fromArray([
        'shopId' => $this->f->shop->id, 'collectFrom' => ONLINE_ORDER_SLOT, 'customerName' => 'Asha', 'customerMobile' => '07700 900123',
        'customerEmail' => 'asha@example.test', 'lines' => [['productId' => $this->beans, 'quantity' => '2', 'price' => '0.01', 'unit_price' => '0.01']],
        'items_total' => '0.02',
    ]);

    $order = app(PlaceCollectOrder::class)->handle($this->f->store, $input)->order;

    expect($order->items_total)->toBe('2.20')->and($order->lines[0]->unit_price)->toBe('1.10');
});

test('the same product twice is one line', function () {
    $order = ($this->place)([['productId' => $this->beans, 'quantity' => '1'], ['productId' => $this->beans, 'quantity' => '2']])->order;

    expect($order->lines)->toHaveCount(1)->and($order->lines[0]->quantity)->toBe('3.0000');
});

test('order numbers count up per business', function () {
    $line = [['productId' => $this->beans, 'quantity' => '1']];
    ($this->place)($line);
    $second = ($this->place)($line, '2026-11-02T10:30:00Z')->order;

    $other = OnlineStoreFixtures::make('Other Co', 'other-co');
    $theirs = $other->order([['productId' => $other->product('Tea'), 'quantity' => '1']], ONLINE_ORDER_SLOT)->order;

    expect($second->reference)->toBe('W-0002')->and($theirs->reference)->toBe('W-0001');
});

test('race-safe numbers: an insert that loses the number to a parallel order is retried with a fresh number', function () {
    ($this->place)([['productId' => $this->beans, 'quantity' => '1']]);
    $counts = 0;

    DB::listen(function (QueryExecuted $query) use (&$counts) {
        if (! preg_match('/^select max\([`"]number[`"]\)/', $query->sql) || ++$counts > 1) {
            return;
        }

        // A parallel order takes W-0002 between this one's count and its insert, so the insert hits the unique index.
        // (One connection in tests: the parallel row goes with the failed attempt's rollback.)
        DB::table('online_orders')->insert([
            'id' => Ulid::new(), 'company_id' => $this->f->company->id, 'online_store_id' => $this->f->store->id, 'online_store_shop_id' => $this->f->shop->id,
            'branch_id' => $this->f->leeds->id, 'number' => 2, 'status' => 'new', 'customer_name' => 'Parallel', 'customer_mobile' => '07700 900000',
            'customer_email' => 'p@example.test', 'collect_from' => '2026-11-02 11:00:00', 'collect_to' => '2026-11-02 11:30:00', 'items_total' => '1.00',
            'token_hash' => hash('sha256', 'parallel'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    // OSA-1: one order per customer per slot, so the second order takes the next slot.
    $order = ($this->place)([['productId' => $this->beans, 'quantity' => '1']], '2026-11-02T10:30:00Z')->order;

    expect($counts)->toBe(2)
        ->and($order->reference)->toBe('W-0002')
        ->and($order->customer_name)->toBe('Asha Patel')
        ->and(OnlineOrder::withoutCompanyScope()->where('company_id', $this->f->company->id)->orderBy('number')->pluck('number')->all())->toBe([1, 2])
        ->and(OnlineOrderLine::withoutCompanyScope()->where('company_id', $this->f->company->id)->count())->toBe(2);
});

test('a full slot refuses the next order; another slot takes it', function () {
    $line = [['productId' => $this->beans, 'quantity' => '1']];
    // OSA-1: one order per customer per slot, so the slot is filled by two other customers.
    ($this->place)($line, ONLINE_ORDER_SLOT, ['customerEmail' => 'b@example.test', 'customerMobile' => '07700 900201']);
    ($this->place)($line, ONLINE_ORDER_SLOT, ['customerEmail' => 'c@example.test', 'customerMobile' => '07700 900202']);

    expect(($this->error)(fn () => ($this->place)($line)))->toBe(['collectFrom' => ['That collection time is now full. Choose another one.']])
        ->and(($this->place)($line, '2026-11-02T10:30:00Z')->order->reference)->toBe('W-0003');
});

test('a slot that is not one of the shop\'s, too soon, or not a time is refused', function (string $slot) {
    expect(($this->error)(fn () => ($this->place)([['productId' => $this->beans, 'quantity' => '1']], $slot)))->toHaveKey('collectFrom');
})->with([
    'between slots' => '2026-11-02T10:10:00Z',
    'before the prep time' => '2026-11-02T07:00:00Z',
    'yesterday' => '2026-11-01T10:00:00Z',
    'after closing' => '2026-11-02T20:00:00Z',
    'not a time' => 'soon',
    'empty' => '',
]);

test('out of stock, excluded or unknown products are refused', function (Closure $setup, string $message) {
    $id = $setup($this->f);

    expect(($this->error)(fn () => ($this->place)([['productId' => $id, 'quantity' => '1']]))['lines.0.productId'][0])->toBe($message);
})->with([
    'out of stock' => [fn ($f) => tap($f->product('Milk', ['track_stock' => true]), fn ($id) => $f->stock($f->leeds, $id, '0')), 'Milk is out of stock.'],
    'alcohol' => [fn ($f) => $f->product('Wine', ['is_alcohol' => true]), 'One of the products is no longer available. Remove it from your basket.'],
    'unknown' => [fn ($f) => Ulid::new(), 'One of the products is no longer available. Remove it from your basket.'],
]);

test('quantities: weighed in 0.25 kg steps, others whole, both within max per sale', function (string $product, string $qty, ?string $error) {
    $id = $product === 'mince' ? $this->mince : $this->beans;
    $call = fn () => ($this->place)([['productId' => $id, 'quantity' => $qty]]);

    if ($error === null) {
        expect($call()->order->lines[0]->quantity)->toBe(number_format((float) $qty, 4, '.', ''));

        return;
    }

    expect(collect(($this->error)($call))->flatten()->first())->toBe($error);
})->with([
    'weighed 0.25' => ['mince', '0.25', null],
    'weighed 2.75' => ['mince', '2.75', null],
    'weighed 0.3' => ['mince', '0.3', 'Choose Lamb mince in steps of 0.25 kg.'],
    'weighed over max' => ['mince', '3.25', 'You can order at most 3 kg of Lamb mince.'],
    'whole 2' => ['beans', '2', null],
    'not whole' => ['beans', '1.5', 'Choose a whole number of Beans.'],
    'over the cap' => ['beans', '100', 'You can order at most 99 of Beans.'],
    'zero' => ['beans', '0', 'Enter a quantity above zero.'],
    'negative' => ['beans', '-1', 'Enter a quantity above zero.'],
    'words' => ['beans', 'two', 'Enter a quantity above zero.'],
]);

test('max per sale applies to whole quantities too', function () {
    $limited = $this->f->product('Paracetamol', ['max_qty_per_sale' => 2]);

    expect(collect(($this->error)(fn () => ($this->place)([['productId' => $limited, 'quantity' => '3']])))->flatten()->first())
        ->toBe('You can order at most 2 of Paracetamol.');
});

test('the customer\'s details are required and checked', function (array $overrides, string $field) {
    expect(($this->error)(fn () => ($this->place)([['productId' => $this->beans, 'quantity' => '1']], ONLINE_ORDER_SLOT, $overrides)))->toHaveKey($field);
})->with([
    'no name' => [['customerName' => ' '], 'customerName'],
    'bad mobile' => [['customerMobile' => 'call me'], 'customerMobile'],
    'bad email' => [['customerEmail' => 'not-an-email'], 'customerEmail'],
]);

test('an empty basket is refused', function () {
    expect(($this->error)(fn () => ($this->place)([])))->toBe(['lines' => ['Your basket is empty.']]);
});

test('refused while not taking orders, off line, or at a shop that is not collecting', function () {
    $line = [['productId' => $this->beans, 'quantity' => '1']];

    app(SetTakingOrders::class)->handle($this->f->company, false);
    expect(($this->error)(fn () => ($this->place)($line)))->toBe(['store' => ['This store is not taking orders right now.']]);

    app(SetTakingOrders::class)->handle($this->f->company, true);
    app(SetStoreLive::class)->handle($this->f->company, false);
    expect(($this->error)(fn () => ($this->place)($line)))->toBe(['store' => ['This store is not taking orders right now.']]);

    app(SetStoreLive::class)->handle($this->f->company, true);
    app(SaveStoreShop::class)->handle($this->f->company, $this->f->leeds->id, OnlineStoreFixtures::shopInput(['enabled' => false]));
    expect(($this->error)(fn () => ($this->place)($line)))->toHaveKey('shopId');
});

test('refused when the online store is switched off after the store was found', function () {
    config(['online_store.enabled' => false]);

    expect(($this->error)(fn () => ($this->place)([['productId' => $this->beans, 'quantity' => '1']])))->toHaveKey('store')
        ->and(OnlineOrder::withoutCompanyScope()->count())->toBe(0);
});

test('the order page: found by its id for its own store only', function () {
    $placed = ($this->place)([['productId' => $this->beans, 'quantity' => '1']]);
    $byId = app(StoreOrderById::class);

    expect($byId->handle($this->f->store, $placed->order->id)?->id)->toBe($placed->order->id)
        ->and($byId->handle($this->f->store, $placed->order->id)?->lines)->toHaveCount(1)
        ->and($byId->handle($this->f->store, str_repeat('A', 26)))->toBeNull()
        ->and($byId->handle($this->f->store, $placed->order->token_hash))->toBeNull()
        ->and($byId->handle($this->f->store, 'short'))->toBeNull();
});
