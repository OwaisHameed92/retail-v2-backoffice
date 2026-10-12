<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * Input of RecordPayment. `allocations` null = oldest open invoice first; otherwise invoice id => amount
 * (pounds, decimal strings) and the rest stays as credit. The online gateway (later) sets `gateway` and
 * `gatewayReference`; the same gateway payment is only ever recorded once (a reversed one does not count: a Direct
 * Debit collected again after a late failure is new money, audit BILL-3). `idempotencyKey`: the staff form's key
 * (audit BILL-9); one key records one payment per business, however many times the form is submitted.
 */
final readonly class NewPayment
{
    /**
     * @param  array<string, string>|null  $allocations
     */
    public function __construct(
        public PaymentMethod $method,
        public string $amount,
        public CarbonImmutable $receivedAt,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?array $allocations = null,
        public ?string $gateway = null,
        public ?string $gatewayReference = null,
        public ?string $idempotencyKey = null,
    ) {}
}
