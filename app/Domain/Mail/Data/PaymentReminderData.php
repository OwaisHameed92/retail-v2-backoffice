<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

final readonly class PaymentReminderData
{
    public const DUE_SOON = 'dueSoon';

    public const DUE_TODAY = 'dueToday';

    public const OVERDUE = 'overdue';

    /**
     * Pakistan plan P5 (manual collection): an invoice paid by hand is coming due, due today, or late.
     *
     * @param  'dueSoon'|'dueToday'|'overdue'  $kind
     * @param  string  $balance  Still owed, as a decimal string.
     * @param  string  $howToPay  "Pay by bank transfer, JazzCash, Easypaisa or cash, quoting INV-000001 as the reference."
     * @param  list<string>  $payLines  Bank account (bank name, account title, IBAN) and wallet accounts; empty hides them.
     * @param  CarbonInterface|null  $locksOn  Overdue only: the day the account is suspended if still unpaid.
     */
    public function __construct(
        public string $businessName,
        public ?string $recipientName,
        public string $invoiceNumber,
        public string $kind,
        public CarbonInterface $dueDate,
        public string $balance,
        public string $howToPay,
        public array $payLines = [],
        public ?CarbonInterface $locksOn = null,
        public ?string $companyId = null,
    ) {}
}
