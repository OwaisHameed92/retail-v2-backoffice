<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Basket\Basket;
use App\Domain\OnlineStore\Basket\PriceBasket;
use App\Domain\OnlineStore\Basket\PricedBasket;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

/**
 * The public store a request is for (OS-2), set by ResolveOnlineStore: the store found by its address, its open
 * collection shops, and the visitor's basket in the session (one per store, keyed by the store's id so a new
 * address keeps it). The basket is priced once per request (PriceBasket) and the cleaned basket saved back.
 * OS-9: the checkout's choice of collect or delivery and the delivery postcode, and the card order being paid
 * (its basket is kept until DNA confirms the payment), are in the session too.
 */
final class StoreContext
{
    public const SESSION_PREFIX = 'online_store.';

    private ?PricedBasket $priced = null;

    /**
     * @param  Collection<int, OnlineStoreShop>  $shops
     */
    public function __construct(
        public readonly OnlineStore $store,
        public readonly Collection $shops,
        private readonly Session $session,
        private readonly PriceBasket $pricer,
    ) {}

    /** The collection shop the visitor shops at: the one chosen, else the first (by name); null when none is open. */
    public function shop(): ?OnlineStoreShop
    {
        $chosen = $this->basket()->shopId;

        return ($chosen !== null ? $this->shops->firstWhere('id', $chosen) : null) ?? $this->shops->first();
    }

    /** Whether customers can add and order now: the store takes orders and a shop is open for collections. */
    public function takingOrders(): bool
    {
        return $this->store->taking_orders && $this->shops->isNotEmpty();
    }

    public function basket(): Basket
    {
        return Basket::fromArray($this->session->get($this->key()));
    }

    public function save(Basket $basket): void
    {
        $this->session->put($this->key(), $basket->toArray());
        $this->priced = null;
    }

    /** The basket priced at the shop now; lines removed or changed on the way are saved, with their notices. */
    public function priced(): PricedBasket
    {
        if ($this->priced !== null) {
            return $this->priced;
        }

        $basket = $this->basket();
        $priced = $this->pricer->handle($this->store, $this->shop(), $basket);

        if ($priced->basket->toArray() !== $basket->toArray()) {
            $this->session->put($this->key(), $priced->basket->toArray());
        }

        return $this->priced = $priced;
    }

    public function chooseShop(OnlineStoreShop $shop): void
    {
        $this->save($this->basket()->atShop($shop->id));
    }

    public function forgetBasket(): void
    {
        $this->save(new Basket([], $this->basket()->shopId));
    }

    /**
     * OS-9: collect or delivery, and the postcode to deliver to, as chosen at checkout.
     *
     * @return array{method: FulfilmentMethod, postcode: string|null}
     */
    public function checkoutChoice(): array
    {
        $saved = $this->session->get(self::SESSION_PREFIX.'checkout.'.$this->store->id);
        $saved = is_array($saved) ? $saved : [];
        $postcode = $saved['postcode'] ?? null;

        return [
            'method' => FulfilmentMethod::tryFrom((string) ($saved['method'] ?? '')) ?? FulfilmentMethod::Collection,
            'postcode' => is_string($postcode) && $postcode !== '' ? mb_substr($postcode, 0, 10) : null,
        ];
    }

    public function chooseCheckout(FulfilmentMethod $method, ?string $postcode): void
    {
        $this->session->put(self::SESSION_PREFIX.'checkout.'.$this->store->id, ['method' => $method->value, 'postcode' => $postcode]);
    }

    /** OS-9: the card order this visitor is paying for on DNA's page (its basket is kept until it is paid). */
    public function payingOrder(): ?string
    {
        $id = $this->session->get(self::SESSION_PREFIX.'paying.'.$this->store->id);

        return is_string($id) ? $id : null;
    }

    public function rememberPaying(?string $orderId): void
    {
        $orderId === null
            ? $this->session->forget(self::SESSION_PREFIX.'paying.'.$this->store->id)
            : $this->session->put(self::SESSION_PREFIX.'paying.'.$this->store->id, $orderId);
    }

    private function key(): string
    {
        return self::SESSION_PREFIX.$this->store->id;
    }
}
