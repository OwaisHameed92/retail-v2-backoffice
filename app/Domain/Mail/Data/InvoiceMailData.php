<?php

namespace App\Domain\Mail\Data;

use App\Domain\Mail\Contracts\RendersAttachment;
use Carbon\CarbonInterface;

final readonly class InvoiceMailData
{
    /**
     * @param  string  $total  Pounds as a decimal string, e.g. "90.00".
     * @param  string  $balance  Still owed, pounds as a decimal string.
     * @param  string  $status  Invoice status value: issued, partiallyPaid, overdue or paid.
     * @param  list<string>  $bankDetails  Lines shown under "How to pay" (account name, sort code, number). Empty hides them.
     * @param  class-string<RendersAttachment>|null  $pdfRenderer  Builds the PDF at send time from `$pdfKey`.
     */
    public function __construct(
        public string $businessName,
        public ?string $recipientName,
        public string $invoiceNumber,
        public string $periodLabel,
        public CarbonInterface $issueDate,
        public CarbonInterface $dueDate,
        public string $total,
        public string $balance,
        public string $status,
        public int $tillCount,
        public bool $resent = false,
        public array $bankDetails = [],
        public ?string $pdfRenderer = null,
        public ?string $pdfKey = null,
        public ?string $companyId = null,
        /** Collected by Direct Debit on this day (module 1.12): "How to pay" says there is nothing to do. */
        public ?CarbonInterface $directDebitOn = null,
    ) {}
}
