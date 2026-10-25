<?php

namespace App\Domain\OnlineStore\Legal;

/**
 * What a store's legal pages need to know about it (OS-11): who the trader is, where customers collect from, and
 * whether it delivers and takes card payments online or payment at the shop or on delivery. Built by the public store
 * (StoreLegalPage) from the store's settings; the templates (TermsOfSale, PrivacyNotice, LegalPages) only read it.
 */
final readonly class LegalFacts
{
    /**
     * @param  list<array{name: string, address: string|null}>  $shops  the collection shops
     */
    public function __construct(
        public StoreTrader $trader,
        public array $shops,
        public bool $delivery,
        public bool $card,
        public bool $payAtShop,
        public bool $payOnDelivery,
        public int $anonymiseMonths,
        /** OS-12: some shop collects (false for a delivery-only store) */
        public bool $collection = true,
    ) {}

    /** "email@shop.test or 0113 496 0000", whichever the trader has. */
    public function contactLine(): string
    {
        $ways = array_values(array_filter([$this->trader->email, $this->trader->phone]));

        return $ways === [] ? 'the shop' : implode(' or ', $ways);
    }
}
