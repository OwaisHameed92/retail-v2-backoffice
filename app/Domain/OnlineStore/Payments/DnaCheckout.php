<?php

namespace App\Domain\OnlineStore\Payments;

/**
 * One card payment to start on DNA's hosted checkout (StartCardPayment): the order's DNA invoice id, the amount to
 * take ("12.50", pounds, from the stored order), where DNA posts the result (server to server) and where it sends
 * the customer back.
 */
final readonly class DnaCheckout
{
    public function __construct(
        public string $invoiceId,
        public string $amount,
        public string $currency,
        public string $description,
        public string $returnUrl,
        public string $failureReturnUrl,
        public string $callbackUrl,
        public ?string $customerEmail = null,
    ) {}
}
