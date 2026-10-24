<?php

namespace App\Domain\OnlineStore\Mail;

use Carbon\CarbonImmutable;

/**
 * What the online order emails show (OnlineOrderMails builds it from an order): plain values only, so the queued
 * message holds no model. Money as "12.50", quantities already trimmed ("2", "0.75"), times UTC.
 */
final readonly class OnlineOrderMailData
{
    /**
     * @param  list<array{name: string, quantity: string, weighed: bool, unitPrice: string, lineTotal: string}>  $lines
     */
    public function __construct(
        public string $businessName,
        public string $shopName,
        public ?string $shopAddress,
        public string $reference,
        public string $customerName,
        public string $customerEmail,
        public string $customerMobile,
        public CarbonImmutable $collectFrom,
        public CarbonImmutable $collectTo,
        public array $lines,
        public string $itemsTotal,
        public ?string $replyTo = null,
        public ?string $orderUrl = null,
        public ?string $portalUrl = null,
        public ?string $cancelReason = null,
        public ?string $companyId = null,
        // OS-9: delivery and payment (defaults: S1's click & collect, paid at the shop).
        public string $fulfilment = 'collection',
        public ?string $deliveryAddress = null,
        public string $deliveryFee = '0.00',
        public ?string $grandTotal = null,
        public string $paymentLabel = 'Pay at the shop when you collect',
        public bool $paid = false,
    ) {}

    public function isDelivery(): bool
    {
        return $this->fulfilment === 'delivery';
    }

    public function hasWeighed(): bool
    {
        return array_filter($this->lines, fn (array $line) => $line['weighed']) !== [];
    }
}
