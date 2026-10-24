<?php

use App\Domain\OnlineStore\Actions\ExpireCardPayments;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Events\OnlineOrderPlaced;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Payments\DnaCheckout;
use App\Domain\OnlineStore\Payments\DnaCredentials;
use App\Domain\OnlineStore\Payments\DnaPaymentException;
use App\Domain\OnlineStore\Payments\HttpDnaClient;
use App\Domain\OnlineStore\Payments\LzString;
use App\Domain\OnlineStore\Queries\CollectionSlots;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\FakeDnaClient;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/*
 * OS-9: card payment online with DNA Payments (hosted checkout). The order waits for its payment (not on the board,
 * slot held); DNA's signed server-to-server result marks it paid (amount and currency checked, once); failures,
 * abandoned payments and expired holds give the slot back. DNA itself is faked: no real call is made.
 */

const CARD_SLOT = '2026-11-02T10:00:00Z';

beforeEach(function () {
    Event::fake([OnlineOrderPlaced::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->dna = FakeDnaClient::bind();
    $this->f = OnlineStoreFixtures::make();
    $this->f->cardOn();
    $this->beans = $this->f->product('Baked beans', ['sell_price' => '1.10']);
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->post(($this->url)('store.basket.add'), ['product' => $this->beans, 'quantity' => '3']);
    $this->pay = fn (array $overrides = []) => $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.place'), [
        'shopId' => $this->f->shop->id, 'collectFrom' => CARD_SLOT, 'customerName' => 'Asha Patel', 'customerMobile' => '07700 900123',
        'customerEmail' => 'asha@example.test', 'paymentMethod' => 'cardOnline', ...$overrides,
    ]);
    $this->order = fn () => OnlineOrder::withoutCompanyScope()->latest('number')->first();
    $this->callback = fn (array $payload) => $this->postJson(route('webhooks.dna', ['store' => $this->f->store->id]), $payload);
    $this->taken = fn () => app(CollectionSlots::class)->find($this->f->shop, CarbonImmutable::parse(CARD_SLOT))?->taken;
});

test('the order waits for its payment and the customer goes to DNA with the stored amount', function () {
    ($this->pay)()->assertRedirect('https://test-pay.dnapayments.com/checkout/?params=fake-'.($this->order)()->payment_invoice_id);

    $order = ($this->order)();
    $checkout = $this->dna->last();
    expect($order->status)->toBe(OnlineOrderStatus::AwaitingPayment)
        ->and($order->payment_method)->toBe(OnlinePaymentMethod::CardOnline)
        ->and($order->payment_expires_at?->toIso8601ZuluString())->toBe('2026-11-02T07:15:00Z')
        ->and($checkout->invoiceId)->toBe('W-0001-'.strtoupper(substr($order->id, -8)))
        ->and($checkout->amount)->toBe('3.30')->and($checkout->currency)->toBe('GBP')
        ->and($checkout->callbackUrl)->toEndWith("/webhooks/dna/{$this->f->store->id}")
        ->and($checkout->failureReturnUrl)->toContain('payment=failed')
        ->and($this->dna->started[0]['credentials']->clientSecret)->toBe(OnlineStoreFixtures::DNA_SECRET)
        ->and(($this->taken)())->toBe(1);
    Event::assertNotDispatched(OnlineOrderPlaced::class);
    // The basket is kept until the payment is confirmed; the order is not on the board.
    expect(session('online_store.'.$this->f->store->id)['lines'])->not->toBe([]);
    $owner = F::member($this->f->company, CompanyRole::Owner);
    $this->actingAs($owner)->get("/app/online-orders/{$order->id}")->assertNotFound();
});

test('a signed success for the right amount marks it paid once: it joins the board and the emails go', function () {
    ($this->pay)();
    $order = ($this->order)();
    $result = FakeDnaClient::result((string) $order->payment_invoice_id, '3.30', OnlineStoreFixtures::DNA_SECRET);

    ($this->callback)($result)->assertOk()->assertJson(['received' => true, 'outcome' => 'paid']);
    $order->refresh();
    expect($order->status)->toBe(OnlineOrderStatus::New)->and($order->isPaid())->toBeTrue()->and($order->paid_amount)->toBe('3.30')
        ->and($order->payment_reference)->toBe($result['id'])->and($order->payment_expires_at)->toBeNull();
    Event::assertDispatchedTimes(OnlineOrderPlaced::class, 1);

    // DNA retries: nothing changes, nothing is sent twice.
    ($this->callback)($result)->assertOk()->assertJson(['outcome' => 'duplicate']);
    ($this->callback)(['paymentResult' => $result])->assertOk()->assertJson(['outcome' => 'duplicate']);
    Event::assertDispatchedTimes(OnlineOrderPlaced::class, 1);
    // A late failure never un-pays it.
    ($this->callback)(FakeDnaClient::result((string) $order->payment_invoice_id, '3.30', OnlineStoreFixtures::DNA_SECRET, false))->assertOk();
    expect($order->refresh()->status)->toBe(OnlineOrderStatus::New);

    // Back from DNA, the page confirms it and the basket empties.
    $this->get(app(StoreLinks::class)->orderPage($order))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('justPlaced', true)->where('order.payment.paid', true)->where('basket.count', 0));
    $owner = F::member($this->f->company, CompanyRole::Owner);
    $this->actingAs($owner)->get('/app/online-orders')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('board.columns.new.cards.0.payment', ['method' => 'cardOnline', 'label' => 'Paid by card', 'paid' => true, 'refundNeeded' => false]));
});

test('a bad signature, another store\'s secret, a missing field or an unknown order changes nothing', function () {
    ($this->pay)();
    $order = ($this->order)();
    $good = FakeDnaClient::result((string) $order->payment_invoice_id, '3.30', OnlineStoreFixtures::DNA_SECRET);

    ($this->callback)([...$good, 'signature' => 'forged'])->assertForbidden();
    ($this->callback)(FakeDnaClient::result((string) $order->payment_invoice_id, '3.30', 'another-secret'))->assertForbidden();
    ($this->callback)(array_diff_key($good, ['errorCode' => true]))->assertForbidden();
    ($this->callback)([...$good, 'amount' => 0.01])->assertForbidden();
    ($this->callback)(FakeDnaClient::result('W-9999-NOTHERE', '3.30', OnlineStoreFixtures::DNA_SECRET))->assertNotFound();
    $this->postJson(route('webhooks.dna', ['store' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']), $good)->assertNotFound();

    expect($order->refresh()->status)->toBe(OnlineOrderStatus::AwaitingPayment)->and($order->isPaid())->toBeFalse();
    Event::assertNotDispatched(OnlineOrderPlaced::class);
});

test('a success for the wrong amount or currency is refused (422) and the order stays unpaid', function () {
    ($this->pay)();
    $order = ($this->order)();

    ($this->callback)(FakeDnaClient::result((string) $order->payment_invoice_id, '0.30', OnlineStoreFixtures::DNA_SECRET))->assertUnprocessable();
    ($this->callback)(FakeDnaClient::result((string) $order->payment_invoice_id, '3.30', OnlineStoreFixtures::DNA_SECRET, true, ['currency' => 'EUR']))->assertUnprocessable();

    expect($order->refresh()->status)->toBe(OnlineOrderStatus::AwaitingPayment)->and($order->isPaid())->toBeFalse();
});

test('a declined payment, a customer who comes back without paying and an expired hold all give the slot back', function () {
    ($this->pay)();
    $declined = ($this->order)();
    ($this->callback)(FakeDnaClient::result((string) $declined->payment_invoice_id, '3.30', OnlineStoreFixtures::DNA_SECRET, false))->assertOk()->assertJson(['outcome' => 'failed']);
    expect($declined->refresh()->status)->toBe(OnlineOrderStatus::PaymentFailed)->and($declined->payment_error)->toBe('Card payment failed: Card declined')
        ->and(($this->taken)())->toBe(0);

    ($this->pay)(['customerEmail' => 'b@example.test']);
    $abandoned = ($this->order)();
    $failure = $this->dna->last()->failureReturnUrl;
    $this->get($failure)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->where('order.payment.failed', true)->whereNot('retryUrl', null));
    expect($abandoned->refresh()->status)->toBe(OnlineOrderStatus::PaymentFailed)
        ->and(session('online_store.'.$this->f->store->id)['lines'])->not->toBe([]);

    ($this->pay)(['customerEmail' => 'c@example.test']);
    $expired = ($this->order)();
    expect(($this->taken)())->toBe(1);
    $this->travel(16)->minutes();
    expect(($this->taken)())->toBe(0)->and(app(ExpireCardPayments::class)->handle())->toBe(1)
        ->and($expired->refresh()->status)->toBe(OnlineOrderStatus::PaymentFailed);

    // DNA confirms it after all: the money was taken, so the order counts and joins the board.
    ($this->callback)(FakeDnaClient::result((string) $expired->payment_invoice_id, '3.30', OnlineStoreFixtures::DNA_SECRET))->assertOk()->assertJson(['outcome' => 'paid']);
    expect($expired->refresh()->status)->toBe(OnlineOrderStatus::New);
});

test('DNA down: the order gives its slot back and the customer can pay another way', function () {
    $this->dna->fail = true;

    ($this->pay)()->assertRedirect(($this->url)('store.checkout'))
        ->assertSessionHasErrors(['paymentMethod' => 'We could not start the card payment. Try again, or choose another way to pay.']);
    expect(($this->order)()->status)->toBe(OnlineOrderStatus::PaymentFailed)->and(($this->taken)())->toBe(0);
});

test('items sold by weight cannot be paid for online; card online needs the DNA details', function () {
    $mince = $this->f->product('Lamb mince', ['sell_price' => '9.80', 'is_weighed' => true, 'unit_code' => 'kg']);
    $this->post(($this->url)('store.basket.add'), ['product' => $mince, 'quantity' => '1']);
    ($this->pay)()->assertSessionHasErrors(['paymentMethod' => 'Items sold by weight are priced on the scale, so they cannot be paid for online. Choose another way to pay.']);
    $this->get(($this->url)('store.checkout'))->assertInertia(fn (AssertableInertia $p) => $p->where('payments.1.value', 'cardOnline')->where('payments.1.available', false));

    $this->delete(($this->url)('store.basket.remove', ['product' => $mince]));
    $this->f->store->forceFill(['dna_client_secret' => null])->save();
    ($this->pay)()->assertSessionHasErrors(['paymentMethod' => 'Choose one of the ways to pay shown.']);
    expect(OnlineOrder::withoutCompanyScope()->count())->toBe(0);
});

test('unpaid card orders never reach the board, the sidebar count or another business', function () {
    $b = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $b->cardOn();
    ($this->pay)();
    $owner = F::member($this->f->company, CompanyRole::Owner);

    $this->actingAs($owner)->get('/app/online-orders')->assertInertia(fn (AssertableInertia $p) => $p->where('board.columns.new.count', 0)->where('onlineOrdersNew', 0));

    $order = ($this->order)();
    // B's secret signs a result for A's order sent to B's address: not found there, and A's order stays unpaid.
    $this->postJson(route('webhooks.dna', ['store' => $b->store->id]), FakeDnaClient::result((string) $order->payment_invoice_id, '3.30', OnlineStoreFixtures::DNA_SECRET))->assertNotFound();
    expect($order->refresh()->isPaid())->toBeFalse();
});

test('the real client asks DNA for a payment token and builds the hosted checkout address (faked HTTP)', function () {
    Http::fake(['test-oauth.dnapayments.com/*' => Http::response(['access_token' => 'tok-1', 'expires_in' => 7200], 200)]);
    $client = new HttpDnaClient;

    $url = $client->checkoutUrl(new DnaCredentials('cid', 'csecret', 'term', true), new DnaCheckout(
        'W-0001-ABCDEFGH', '3.30', 'GBP', 'Kirkgate online order W-0001', 'https://shop.test/o/1', 'https://shop.test/o/1?payment=failed', 'https://app.test/webhooks/dna/1', 'a@b.test',
    ));

    Http::assertSent(fn ($request) => $request->url() === 'https://test-oauth.dnapayments.com/oauth2/token' && $request['grant_type'] === 'client_credentials'
        && $request['scope'] === 'payment integration_hosted' && $request['terminal'] === 'term' && $request['invoiceId'] === 'W-0001-ABCDEFGH'
        && (string) $request['amount'] === '3.3' && $request['currency'] === 'GBP');
    expect($url)->toStartWith('https://test-pay.dnapayments.com/checkout/?params=')
        ->and($url)->toContain('&data='.base64_encode(LzString::compressToEncodedURIComponent('{"isTestMode":true,"scopes":{"allowHosted":true},"autoRedirectDelayInMs":5000}')));
});

test('the real client turns a refusal from DNA into a payment error', function () {
    Http::fake(['test-oauth.dnapayments.com/*' => Http::response(['error' => 'invalid_client'], 401)]);
    $client = new HttpDnaClient;

    expect(fn () => $client->checkoutUrl(new DnaCredentials('cid', 'csecret', 'term', true), new DnaCheckout(
        'W-1', '1.00', 'GBP', 'x', 'https://a', 'https://b', 'https://c',
    )))->toThrow(DnaPaymentException::class);
});
