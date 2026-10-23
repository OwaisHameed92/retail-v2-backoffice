<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Data\CollectOrderInput;
use App\Domain\OnlineStore\Data\PlacedOrder;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Events\OnlineOrderPlaced;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Queries\CollectionSlots;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\OnlineStore\Queries\StorefrontShops;
use App\Domain\OnlineStore\Support\OrderLineRules;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * A customer's click & collect order, paid at the shop (docs/online-store.md "Orders"). Public: no signed-in user;
 * everything runs scoped to the store's own business (StoreScope).
 *
 * Refused unless the store is live and taking orders (FindStoreBySlug::takingOrders), the shop is one of its
 * collection shops, the slot is one of that shop's and not full, and every line is shown and orderable at the
 * shop with an allowed quantity (OrderLineRules). Names and prices are read on the server, never taken from the
 * request. The store row is locked while the slot is counted and the number taken, so two orders can neither
 * overfill a slot nor share a number; a number taken anyway (unique index) is retried with the next one.
 *
 * Returns the order and its page's plain token, given out only here: only the token's sha256 is stored. Audited
 * (no actor: the customer). OnlineOrderPlaced is dispatched after commit. The order never writes till data.
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
        $customer = self::customer($input);
        $quantities = OrderLineRules::requested($input->lines);

        if (! $this->stores->takingOrders($store)) {
            throw ValidationException::withMessages(['store' => 'This store is not taking orders right now.']);
        }

        $shop = $this->shops->find($store, $input->shopId)
            ?? throw ValidationException::withMessages(['shopId' => 'Choose one of the shops listed.']);
        $from = self::slotStart($input->collectFrom);
        $lines = OrderLineRules::price($quantities, $this->catalogue->items($store, $shop, array_map('strval', array_keys($quantities))));
        $token = bin2hex(random_bytes(32));

        for ($attempt = 1; ; $attempt++) {
            try {
                $order = StoreScope::run($store, fn (): OnlineOrder => DB::transaction(
                    fn (): OnlineOrder => $this->insert($store, $shop, $from, $customer, $lines, $token, $now),
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
            'items_total' => $order->items_total, 'lines' => count($lines),
        ], companyId: $store->company_id);
        OnlineOrderPlaced::dispatch($order);

        return new PlacedOrder($order, $token);
    }

    /**
     * @param  array{customer_name: string, customer_mobile: string, customer_email: string}  $customer
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    private function insert(OnlineStore $store, OnlineStoreShop $shop, CarbonImmutable $from, array $customer, array $lines, string $token, ?CarbonImmutable $now): OnlineOrder
    {
        $locked = OnlineStore::query()->where('company_id', $store->company_id)->whereKey($store->id)->lockForUpdate()->first();

        if ($locked === null || ! $locked->is_live || ! $locked->taking_orders) {
            throw ValidationException::withMessages(['store' => 'This store is not taking orders right now.']);
        }

        $slot = $this->slots->find($shop, $from, $now)
            ?? throw ValidationException::withMessages(['collectFrom' => 'Choose one of the collection times shown.']);

        if (! $slot->available()) {
            throw ValidationException::withMessages(['collectFrom' => 'That collection time is now full. Choose another one.']);
        }

        $number = 1 + (int) OnlineOrder::query()->where('company_id', $store->company_id)->max('number');

        $order = OnlineOrder::query()->create([
            ...$customer,
            'company_id' => $store->company_id,
            'online_store_id' => $store->id,
            'online_store_shop_id' => $shop->id,
            'branch_id' => $shop->branch_id,
            'number' => $number,
            'status' => OnlineOrderStatus::New,
            'collect_from' => $slot->from,
            'collect_to' => $slot->to,
            'items_total' => Money::sum(array_column($lines, 'line_total'), 2),
            'token_hash' => OnlineOrder::hashToken($token),
        ]);

        foreach ($lines as $i => $line) {
            OnlineOrderLine::query()->create([...$line, 'company_id' => $store->company_id, 'online_order_id' => $order->id, 'position' => $i + 1]);
        }

        return $order->load(['lines', 'shop.branch', 'branch']);
    }

    /**
     * @return array{customer_name: string, customer_mobile: string, customer_email: string}
     *
     * @throws ValidationException
     */
    private static function customer(CollectOrderInput $input): array
    {
        $data = [
            'customerName' => trim($input->customerName),
            'customerMobile' => trim($input->customerMobile),
            'customerEmail' => strtolower(trim($input->customerEmail)),
        ];

        Validator::make($data, [
            'customerName' => ['required', 'string', 'min:2', 'max:120'],
            'customerMobile' => ['required', 'string', 'max:30', 'regex:'.app(Country::class)->phone()['pattern']],
            'customerEmail' => ['required', 'email', 'max:255'],
        ], [
            'customerName.required' => 'Enter your name.',
            'customerMobile.required' => 'Enter your mobile number.',
            'customerMobile.regex' => 'Enter a mobile number we can reach you on.',
            'customerEmail.required' => 'Enter your email address.',
            'customerEmail.email' => 'Enter a valid email address.',
        ])->validate();

        return ['customer_name' => $data['customerName'], 'customer_mobile' => $data['customerMobile'], 'customer_email' => $data['customerEmail']];
    }

    /**
     * @throws ValidationException
     */
    private static function slotStart(string $value): CarbonImmutable
    {
        try {
            if (trim($value) === '') {
                throw new InvalidArgumentException('No slot.');
            }

            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages(['collectFrom' => 'Choose one of the collection times shown.']);
        }
    }
}
