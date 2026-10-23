<?php

use App\Domain\OnlineStore\Actions\SaveStoreShop;
use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->beans = $this->f->product('Baked beans', ['sell_price' => '1.10', 'max_qty_per_sale' => 6]);
    $this->mince = $this->f->product('Lamb mince', ['sell_price' => '9.80', 'is_weighed' => true, 'unit_code' => 'kg']);
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->add = fn (string $product, string $quantity) => $this->from(($this->url)('store.home'))
        ->post(($this->url)('store.basket.add'), ['product' => $product, 'quantity' => $quantity]);
    $this->basket = fn () => $this->get(($this->url)('store.basket'));
    $this->stored = fn () => session('online_store.'.$this->f->store->id);
});

test('add, change and remove lines; the session keeps ids and quantities only, never prices', function () {
    ($this->add)($this->beans, '2')->assertRedirect(($this->url)('store.home'))->assertSessionHas('success', 'Added Baked beans to your basket (2 in it now).');
    ($this->add)($this->beans, '1');
    ($this->add)($this->mince, '0.75');

    expect(($this->stored)())->toBe(['lines' => [
        $this->beans => ['qty' => '3.0000', 'name' => 'Baked beans'],
        $this->mince => ['qty' => '0.7500', 'name' => 'Lamb mince'],
    ], 'shopId' => null]);

    ($this->basket)()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('store/basket')
        ->where('lines.0.product.name', 'Baked beans')->where('lines.0.quantity', '3')->where('lines.0.lineTotal', '3.30')
        ->where('lines.1.product.isWeighed', true)->where('lines.1.quantity', '0.75')->where('lines.1.lineTotal', '7.35')
        ->where('basket', ['count' => 4, 'total' => '10.65', 'estimated' => true, 'notices' => []]));

    $this->patch(($this->url)('store.basket.update', ['product' => $this->beans]), ['quantity' => '5'])->assertRedirect();
    $this->delete(($this->url)('store.basket.remove', ['product' => $this->mince]))->assertRedirect();

    ($this->basket)()->assertInertia(fn (AssertableInertia $page) => $page->has('lines', 1)->where('lines.0.quantity', '5')
        ->where('basket.total', '5.50')->where('basket.estimated', false));

    $this->patch(($this->url)('store.basket.update', ['product' => $this->beans]), ['quantity' => '0']);
    expect(($this->stored)()['lines'])->toBe([]);
});

test('the basket is priced again from the catalogue on every view', function () {
    ($this->add)($this->beans, '2');
    ($this->basket)()->assertInertia(fn (AssertableInertia $page) => $page->where('basket.total', '2.20'));

    $this->f->shopPrice($this->f->leeds, $this->beans, '0.95');
    ($this->basket)()->assertInertia(fn (AssertableInertia $page) => $page->where('lines.0.lineTotal', '1.90')->where('basket.total', '1.90'));

    DB::table('products')->where('id', $this->beans)->update(['sell_price' => '1.50']);
    DB::table('branch_prices')->where('product_id', $this->beans)->delete();
    ($this->basket)()->assertInertia(fn (AssertableInertia $page) => $page->where('basket.total', '3.00'));
});

test('lines no longer orderable are removed with a notice, once; a lowered limit fits the quantity', function () {
    $crisps = $this->f->product('Crisps', ['track_stock' => true]);
    $this->f->stock($this->f->leeds, $crisps, '5');
    ($this->add)($this->beans, '6');
    ($this->add)($crisps, '1');
    ($this->add)($this->mince, '1');

    DB::table('branch_products')->where('product_id', $crisps)->update(['qty_on_hand' => '0']);
    app(SetOnlineProduct::class)->handle($this->f->company, $this->mince, ['hidden' => true]);
    DB::table('products')->where('id', $this->beans)->update(['max_qty_per_sale' => 4]);

    ($this->basket)()->assertInertia(fn (AssertableInertia $page) => $page->has('lines', 1)->where('lines.0.quantity', '4')
        ->where('basket.notices', [
            'We changed Baked beans to 4 to fit what one order can have.',
            'Crisps has just sold out, so we took it out of your basket.',
            'Lamb mince is no longer available, so we took it out of your basket.',
        ]));
    expect(array_keys(($this->stored)()['lines']))->toBe([$this->beans]);

    ($this->basket)()->assertInertia(fn (AssertableInertia $page) => $page->where('basket.notices', []));
});

test('weighed products go in 0.25 kg steps; unit products in whole numbers; limits hold', function () {
    ($this->add)($this->mince, '0.3')->assertSessionHasErrors(['quantity' => 'Choose Lamb mince in steps of 0.25 kg.']);
    ($this->add)($this->beans, '1.5')->assertSessionHasErrors(['quantity' => 'Choose a whole number of Baked beans.']);
    ($this->add)($this->beans, '7')->assertSessionHasErrors(['quantity' => 'You can order at most 6 of Baked beans.']);
    ($this->add)($this->beans, '0')->assertSessionHasErrors(['quantity' => 'Enter a quantity above zero.']);
    ($this->add)($this->beans, 'two')->assertSessionHasErrors('quantity');
    ($this->add)($this->beans, '5');
    ($this->add)($this->beans, '2')->assertSessionHasErrors(['quantity' => 'You can order at most 6 of Baked beans.']);
    ($this->add)($this->mince, '51')->assertSessionHasErrors(['quantity' => 'You can order at most 50 kg of Lamb mince.']);

    expect(($this->stored)()['lines'])->toBe([$this->beans => ['qty' => '5.0000', 'name' => 'Baked beans']]);
});

test('out of stock, hidden, age-restricted or unknown products cannot be added', function () {
    $crisps = $this->f->product('Crisps', ['track_stock' => true]);
    $wine = $this->f->product('Red wine', ['is_alcohol' => true]);

    ($this->add)($crisps, '1')->assertSessionHasErrors(['product' => 'Crisps is out of stock.']);
    ($this->add)($wine, '1')->assertSessionHasErrors('product');
    ($this->add)('01K5T0Q8C4000000000000P999', '1')->assertSessionHasErrors('product');
    ($this->add)('../../etc', '1')->assertSessionHasErrors('product');
    $this->patch(($this->url)('store.basket.update', ['product' => $this->beans]), ['quantity' => '2'])
        ->assertSessionHasErrors(['product' => 'That product is not in your basket.']);

    expect(($this->stored)())->toBeNull();
});

test('validation answers 422 to JSON callers', function () {
    $this->postJson(($this->url)('store.basket.add'), ['product' => $this->beans, 'quantity' => '0.3'])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity' => 'Choose a whole number of Baked beans.']);
});

test('basket changes need the CSRF token (419)', function () {
    $this->app->instance(ValidateCsrfToken::class, new class(app(), app('encrypter')) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $this->postJson(($this->url)('store.basket.add'), ['product' => $this->beans, 'quantity' => '1'])->assertStatus(419);
    $this->deleteJson(($this->url)('store.basket.remove', ['product' => $this->beans]))->assertStatus(419);
    expect(($this->stored)())->toBeNull();
});

test('basket changes are rate limited per visitor and store (429 with the store\'s page)', function () {
    config(['online_store.basket_per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        ($this->add)($this->beans, '1')->assertRedirect();
    }

    ($this->add)($this->beans, '1')->assertStatus(429)->assertHeader('Retry-After')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('store/error')->where('status', 429)->where('store.name', 'Kirkgate Convenience'));

    // Another store keeps its own budget.
    $other = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $this->post(route('store.basket.add', ['slug' => 'briggate'], false), ['product' => $other->product('Steak'), 'quantity' => '1'])->assertRedirect();
});

test('more than one shop: the visitor picks one; prices and stock follow it; only this store\'s shops', function () {
    $bradford = app(SaveStoreShop::class)->handle($this->f->company, $this->f->bradford->id, OnlineStoreFixtures::shopInput());
    $this->f->shopPrice($this->f->bradford, $this->beans, '0.80');
    ($this->add)($this->beans, '2');

    ($this->basket)()->assertInertia(fn (AssertableInertia $page) => $page->where('store.shop.name', 'Bradford')->where('store.shopCount', 2)
        ->where('basket.total', '1.60'));

    $this->from(($this->url)('store.checkout'))->post(($this->url)('store.shop'), ['shopId' => $this->f->shop->id])->assertRedirect(($this->url)('store.checkout'));
    ($this->basket)()->assertInertia(fn (AssertableInertia $page) => $page->where('store.shop.name', 'Leeds')->where('basket.total', '2.20'));

    $other = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $this->post(($this->url)('store.shop'), ['shopId' => $other->shop->id])->assertSessionHasErrors(['shopId' => 'Choose one of the shops listed.']);
    expect(($this->stored)()['shopId'])->toBe($this->f->shop->id)->and($bradford->id)->not->toBe($this->f->shop->id);
});
