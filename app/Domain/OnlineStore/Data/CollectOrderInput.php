<?php

namespace App\Domain\OnlineStore\Data;

/**
 * What a customer sends to place an online order (PlaceCollectOrder). Only product ids and quantities come from the
 * customer: names and prices are always read again on the server, and so are the delivery fee, minimum and the
 * ways to pay (OS-9). Without `fulfilment` / `paymentMethod` it is S1's click & collect, paid at the shop.
 */
final readonly class CollectOrderInput
{
    /**
     * @param  string  $shopId  the online_store_shops id
     * @param  string  $collectFrom  the slot's start (collection time or delivery window), ISO 8601 (any offset; compared in UTC)
     * @param  list<array{productId: string, quantity: string}>  $lines
     * @param  string  $fulfilment  "collection" or "delivery"
     * @param  string  $paymentMethod  "payAtShop", "cardOnline", "payOnDelivery"; "" = the method's only way to pay
     */
    public function __construct(
        public string $shopId,
        public string $collectFrom,
        public string $customerName,
        public string $customerMobile,
        public string $customerEmail,
        public array $lines,
        public string $fulfilment = 'collection',
        public string $paymentMethod = '',
        public string $deliveryLine1 = '',
        public string $deliveryLine2 = '',
        public string $deliveryTown = '',
        public string $deliveryPostcode = '',
        public string $deliveryNotes = '',
        public string $customerNote = '',
        public bool $substitutionsOk = false,
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

        $text = fn (string $camel, string $snake): string => (string) ($input[$camel] ?? $input[$snake] ?? '');

        return new self(
            shopId: $text('shopId', 'shop_id'),
            collectFrom: $text('collectFrom', 'collect_from'),
            customerName: $text('customerName', 'customer_name'),
            customerMobile: $text('customerMobile', 'customer_mobile'),
            customerEmail: $text('customerEmail', 'customer_email'),
            lines: $lines,
            fulfilment: $text('fulfilment', 'fulfilment') ?: 'collection',
            paymentMethod: $text('paymentMethod', 'payment_method'),
            deliveryLine1: $text('deliveryLine1', 'delivery_line1'),
            deliveryLine2: $text('deliveryLine2', 'delivery_line2'),
            deliveryTown: $text('deliveryTown', 'delivery_town'),
            deliveryPostcode: $text('deliveryPostcode', 'delivery_postcode'),
            deliveryNotes: $text('deliveryNotes', 'delivery_notes'),
            customerNote: $text('customerNote', 'customer_note'),
            substitutionsOk: filter_var($input['substitutionsOk'] ?? $input['substitutions_ok'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }
}
