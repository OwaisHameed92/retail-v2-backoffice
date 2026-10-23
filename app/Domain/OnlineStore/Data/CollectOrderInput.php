<?php

namespace App\Domain\OnlineStore\Data;

/**
 * What a customer sends to place a click & collect order (PlaceCollectOrder). Only product ids and quantities come
 * from the customer: names and prices are always read again on the server.
 */
final readonly class CollectOrderInput
{
    /**
     * @param  string  $shopId  the online_store_shops id
     * @param  string  $collectFrom  the slot's start, ISO 8601 (any offset; compared in UTC)
     * @param  list<array{productId: string, quantity: string}>  $lines
     */
    public function __construct(
        public string $shopId,
        public string $collectFrom,
        public string $customerName,
        public string $customerMobile,
        public string $customerEmail,
        public array $lines,
    ) {}

    /**
     * From a request's validated input (snake_case or camelCase keys).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $lines = [];

        foreach ((array) ($input['lines'] ?? []) as $line) {
            $line = (array) $line;
            $lines[] = ['productId' => (string) ($line['productId'] ?? $line['product_id'] ?? ''), 'quantity' => (string) ($line['quantity'] ?? '')];
        }

        return new self(
            shopId: (string) ($input['shopId'] ?? $input['shop_id'] ?? ''),
            collectFrom: (string) ($input['collectFrom'] ?? $input['collect_from'] ?? ''),
            customerName: (string) ($input['customerName'] ?? $input['customer_name'] ?? ''),
            customerMobile: (string) ($input['customerMobile'] ?? $input['customer_mobile'] ?? ''),
            customerEmail: (string) ($input['customerEmail'] ?? $input['customer_email'] ?? ''),
            lines: $lines,
        );
    }
}
