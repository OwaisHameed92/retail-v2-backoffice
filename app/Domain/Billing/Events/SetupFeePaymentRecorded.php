<?php

namespace App\Domain\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A business's setup fee moved on: one of its setup fee invoices was paid in full (SettleInvoice, inside the payment's
 * transaction) or "nothing to pay" was recorded (RecordUpfrontPayment, £0). Billing does nothing with it; listeners
 * read the setup fee state themselves (the partner API sends `tenant.setup_fee_paid` once it is settled).
 */
final class SetupFeePaymentRecorded
{
    use Dispatchable;

    public function __construct(public readonly string $companyId) {}
}
