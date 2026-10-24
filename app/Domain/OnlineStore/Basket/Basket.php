<?php

namespace App\Domain\OnlineStore\Basket;

/**
 * A customer's basket in one store (OS-2): product ids with quantities, in the order added, and the collection shop
 * chosen (null = the store's first). The product name is kept only to name a line that disappears from the
 * catalogue; prices are never kept: PriceBasket reads them from the catalogue on every view. Kept in the session
 * by the public store (StoreSession). `price` (audit OSA-24) is only the last price the customer was shown, never
 * charged: a change since then is told to the customer before an order is placed (PriceBasket).
 */
final readonly class Basket
{
    private const ID = '/^[0-9A-Za-z]{26}$/';

    private const QUANTITY = '/^\d{1,6}(\.\d{1,4})?$/';

    /**
     * @param  array<string, array{qty: string, name: string, price?: string}>  $lines  product id => line
     */
    public function __construct(public array $lines = [], public ?string $shopId = null) {}

    /** From the session; anything malformed is dropped. */
    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $id => $line) {
            $qty = is_array($line) ? (string) ($line['qty'] ?? '') : '';

            if (is_string($id) && preg_match(self::ID, $id) === 1 && preg_match(self::QUANTITY, $qty) === 1) {
                $price = (string) ($line['price'] ?? '');
                $lines[$id] = ['qty' => $qty, 'name' => mb_substr((string) ($line['name'] ?? ''), 0, 200)]
                    + (preg_match('/^\d{1,8}\.\d{2}$/', $price) === 1 ? ['price' => $price] : []);
            }
        }

        $shop = $data['shopId'] ?? null;

        return new self($lines, is_string($shop) && preg_match(self::ID, $shop) === 1 ? $shop : null);
    }

    /**
     * @return array{lines: array<string, array{qty: string, name: string, price?: string}>, shopId: string|null}
     */
    public function toArray(): array
    {
        return ['lines' => $this->lines, 'shopId' => $this->shopId];
    }

    public function has(string $productId): bool
    {
        return isset($this->lines[$productId]);
    }

    /** The quantity of a product in the basket, "0" when it is not in it. */
    /** The price last shown for a product (OSA-24), or null. */
    public function shownPrice(string $productId): ?string
    {
        return $this->lines[$productId]['price'] ?? null;
    }

    public function quantity(string $productId): string
    {
        return $this->lines[$productId]['qty'] ?? '0';
    }

    public function with(string $productId, string $quantity, string $name, ?string $price = null): self
    {
        $lines = $this->lines;
        $lines[$productId] = ['qty' => $quantity, 'name' => $name] + ($price !== null ? ['price' => $price] : []);

        return new self($lines, $this->shopId);
    }

    public function without(string $productId): self
    {
        $lines = $this->lines;
        unset($lines[$productId]);

        return new self($lines, $this->shopId);
    }

    public function atShop(?string $shopId): self
    {
        return new self($this->lines, $shopId);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * The lines as PlaceCollectOrder takes them (CollectOrderInput).
     *
     * @return list<array{productId: string, quantity: string}>
     */
    public function orderLines(): array
    {
        $lines = [];

        foreach ($this->lines as $id => $line) {
            $lines[] = ['productId' => (string) $id, 'quantity' => $line['qty']];
        }

        return $lines;
    }
}
