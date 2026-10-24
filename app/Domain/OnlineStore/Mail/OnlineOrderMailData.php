<?php

namespace App\Domain\OnlineStore\Mail;

use Carbon\CarbonImmutable;

/**
 * What the online order emails show (OnlineOrderMessages builds it from an order): plain values only, so the queued
 * message holds no model. Money as "12.50", quantities already trimmed ("2", "0.75"), times UTC. New fields are
 * optional (with a default) and read with isset(), so an email queued before a deploy still renders.
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
        public ?StoreMailBrand $brand = null,
        public ?string $senderName = null,
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

    /**
     * The shop's look for customer emails (OS-10). An email queued before OS-10 has no brand: the business name on
     * the default colour.
     */
    public function brand(): StoreMailBrand
    {
        return isset($this->brand) ? $this->brand : StoreMailBrand::plain($this->sender());
    }

    /** The From name: the store's sender name, else the business name. Never Switch & Save. */
    public function sender(): string
    {
        $name = isset($this->senderName) ? trim((string) $this->senderName) : '';

        return $name !== '' && ! SenderName::isPlatform($name) ? $name : $this->businessName;
    }

    /**
     * A copy with the store's look, sender name and Reply-To (the Emails tab's previews and test email).
     */
    public function withStore(StoreMailBrand $brand, string $senderName, ?string $replyTo, ?string $companyId = null): self
    {
        $values = get_object_vars($this);
        $values['companyId'] = $companyId ?? $this->companyId;
        $values['brand'] = $brand;
        $values['senderName'] = $senderName;
        $values['replyTo'] = $replyTo;
        $values['businessName'] = $brand->name;

        return new self(...$values);
    }

    public function hasWeighed(): bool
    {
        return array_filter($this->lines, fn (array $line) => $line['weighed']) !== [];
    }
}
