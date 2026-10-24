<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Data\CollectOrderInput;
use App\Domain\OnlineStore\Data\DeliveryQuote;
use App\Domain\OnlineStore\Data\PlacedOrder;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Events\OnlineOrderPlaced;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Queries\CollectionSlots;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\OnlineStore\Queries\StorefrontShops;
use App\Domain\OnlineStore\Support\OrderAbuseGuard;
use App\Domain\OnlineStore\Support\OrderDetailsRules;
use App\Domain\OnlineStore\Support\OrderLineRules;
use App\Domain\OnlineStore\Support\PaymentOptions;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * A customer's online order (docs/online-store.md "Orders"): click & collect or, from OS-9, local delivery; paid at
 * the shop, on delivery or by card online. Public: no signed-in user; everything runs scoped to the store's own
 * business (StoreScope).
 *
 * Refused unless the store is live and taking orders (FindStoreBySlug::takingOrders), the shop is one of its
 * shops, the slot is one of that shop's for the method and not full, and every line is shown and orderable at the
 * shop with an allowed quantity (OrderLineRules). A delivery also needs the shop to deliver to the postcode and the
 * minimum order (DeliveryQuote, which sets the fee). The way to pay must be one the store offers for the method now
 * (PaymentOptions); card online is not offered for items sold by weight (the scale sets their price). Names, prices
 * and fees are read on the server, never taken from the request. The store row is locked while the slot is counted
 * and the number taken, so two orders can neither overfill a slot nor share a number; a number taken anyway (unique
 * index) is retried with the next one. The limits against fake orders (OrderAbuseGuard, audit OSA-1) are checked
 * under the same lock.
 *
 * A card order is created awaiting payment: not on the board, its slot held for `online_store.card_hold_minutes`
 * (StartCardPayment sends the customer to DNA; RecordCardPayment makes it new once paid). Any other order is new at
 * once and OnlineOrderPlaced is dispatched after commit.
 *
 * Returns the order and its page's plain token, given out only here: only the token's sha256 is stored. Audited
 * (no actor: the customer). The order never writes till data.
 */
final class PlaceCollectOrder
{
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly FindStoreBySlug $stores,
        private readonly StorefrontShops $shops,
        private readonly StorefrontCatalogue $catalogue,
        private readonly CollectionSlots $slots,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(OnlineStore $store, CollectOrderInput $input, ?CarbonImmutable $now = null): PlacedOrder
    {
        $customer = OrderDetailsRules::customer($input);
        $method = FulfilmentMethod::tryFrom($input->fulfilment)
            ?? throw ValidationException::withMessages(['fulfilment' => 'Choose click & collect or delivery.']);
        $address = $method === FulfilmentMethod::Delivery ? OrderDetailsRules::address($input) : [];
        $quantities = OrderLineRules::requested($input->lines);

        if (! $this->stores->takingOrders($store)) {
            throw ValidationException::withMessages(['store' => 'This store is not taking orders right now.']);
        }

        $shop = $this->shops->find($store, $input->shopId)
            ?? throw ValidationException::withMessages(['shopId' => 'Choose one of the shops listed.']);
        $from = self::slotStart($input->collectFrom, $method);
        $lines = OrderLineRules::price($quantities, $this->catalogue->items($store, $shop, array_map('strval', array_keys($quantities))));
        $items = Money::sum(array_column($lines, 'line_total'), 2);
        $fee = $method === FulfilmentMethod::Delivery ? self::deliveryFee($shop, $address['delivery_postcode'], $items) : '0.00';
        $payment = self::payment($store, $method, $input->paymentMethod, $lines);
        $token = bin2hex(random_bytes(32));
        $totals = ['items_total' => $items, 'delivery_fee' => $fee, 'grand_total' => Money::add($items, $fee, 2)];
        $details = [...$customer, ...$address, ...$totals, 'fulfilment' => $method, 'payment_method' => $payment];

        for ($attempt = 1; ; $attempt++) {
            try {
                $order = StoreScope::run($store, fn (): OnlineOrder => DB::transaction(
                    fn (): OnlineOrder => $this->insert($store, $shop, $from, $details, $lines, $token, $now),
                ));

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }

        $this->audit->handle('online_order.placed', $order, null, [
            'number' => $order->reference, 'shop' => $shop->branch->name, 'collect_from' => $order->collect_from->toIso8601ZuluString(),
            'items_total' => $order->items_total, 'lines' => count($lines), 'fulfilment' => $method->value, 'payment_method' => $payment->value,
            ...($method === FulfilmentMethod::Delivery ? ['delivery_fee' => $fee, 'grand_total' => $order->grandTotal()] : []),
        ], companyId: $store->company_id);

        if ($order->status === OnlineOrderStatus::New) {
            OnlineOrderPlaced::dispatch($order);
        }

        return new PlacedOrder($order, $token);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    private function insert(OnlineStore $store, OnlineStoreShop $shop, CarbonImmutable $from, array $details, array $lines, string $token, ?CarbonImmutable $now): OnlineOrder
    {
        $locked = OnlineStore::query()->where('company_id', $store->company_id)->whereKey($store->id)->lockForUpdate()->first();

        if ($locked === null || ! $locked->is_live || ! $locked->taking_orders) {
            throw ValidationException::withMessages(['store' => 'This store is not taking orders right now.']);
        }

        $method = $details['fulfilment'];
        $word = $method === FulfilmentMethod::Delivery ? 'delivery' : 'collection';
        $slot = $this->slots->find($shop, $from, $now, $method)
            ?? throw ValidationException::withMessages(['collectFrom' => "Choose one of the {$word} times shown."]);

        if (! $slot->available()) {
            throw ValidationException::withMessages(['collectFrom' => "That {$word} time is now full. Choose another one."]);
        }

        OrderAbuseGuard::check($store, $method, $slot->from, (string) $details['customer_email'], (string) $details['customer_mobile_key'], ($now ?? CarbonImmutable::now())->utc());

        $number = 1 + (int) OnlineOrder::query()->where('company_id', $store->company_id)->max('number');
        $card = $details['payment_method'] === OnlinePaymentMethod::CardOnline;

        $order = OnlineOrder::query()->create([
            ...$details,
            'company_id' => $store->company_id,
            'online_store_id' => $store->id,
            'online_store_shop_id' => $shop->id,
            'branch_id' => $shop->branch_id,
            'number' => $number,
            'status' => $card ? OnlineOrderStatus::AwaitingPayment : OnlineOrderStatus::New,
            'collect_from' => $slot->from,
            'collect_to' => $slot->to,
            'token_hash' => OnlineOrder::hashToken($token),
            ...($card ? [
                'payment_expires_at' => ($now ?? CarbonImmutable::now())->utc()->addMinutes(max(1, (int) config('online_store.card_hold_minutes', 15))),
            ] : []),
        ]);

        if ($card) {
            $order->forceFill(['payment_invoice_id' => $order->reference.'-'.strtoupper(substr($order->id, -8))])->save();
        }

        foreach ($lines as $i => $line) {
            OnlineOrderLine::query()->create([...$line, 'company_id' => $store->company_id, 'online_order_id' => $order->id, 'position' => $i + 1]);
        }

        return $order->load(['lines', 'shop.branch', 'branch']);
    }

    /**
     * The delivery fee for these items to this postcode, once the shop delivers there and the minimum is reached.
     *
     * @throws ValidationException
     */
    private static function deliveryFee(OnlineStoreShop $shop, string $postcode, string $items): string
    {
        $quote = DeliveryQuote::for($shop, $postcode, $items);

        if (! $quote->deliverable()) {
            throw ValidationException::withMessages([$quote->shortBy !== null && $quote->covered ? 'lines' : 'deliveryPostcode' => (string) $quote->problem]);
        }

        return $quote->fee;
    }

    /**
     * The way to pay: the one chosen (or the method's only one), if the store offers it for this method now.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    private static function payment(OnlineStore $store, FulfilmentMethod $method, string $chosen, array $lines): OnlinePaymentMethod
    {
        $options = PaymentOptions::for($store, $method);

        if ($options === []) {
            throw ValidationException::withMessages(['fulfilment' => 'Delivery is not available right now. Choose click & collect.']);
        }

        $payment = trim($chosen) === '' && count($options) === 1 ? $options[0] : OnlinePaymentMethod::tryFrom($chosen);

        if ($payment === null || ! in_array($payment, $options, true)) {
            throw ValidationException::withMessages(['paymentMethod' => 'Choose one of the ways to pay shown.']);
        }

        if ($payment === OnlinePaymentMethod::CardOnline && in_array(true, array_column($lines, 'is_weighed'), true)) {
            throw ValidationException::withMessages(['paymentMethod' => 'Items sold by weight are priced on the scale, so they cannot be paid for online. Choose another way to pay.']);
        }

        return $payment;
    }

    /**
     * @throws ValidationException
     */
    private static function slotStart(string $value, FulfilmentMethod $method): CarbonImmutable
    {
        try {
            if (trim($value) === '') {
                throw new InvalidArgumentException('No slot.');
            }

            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages(['collectFrom' => 'Choose one of the '.($method === FulfilmentMethod::Delivery ? 'delivery' : 'collection').' times shown.']);
        }
    }
}
