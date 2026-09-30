<?php

namespace App\Domain\Mail\Data;

use App\Domain\Mail\Contracts\RendersAttachment;

final readonly class CustomerStatementData
{
    /**
     * @param  string  $closingBalance  Pounds as a decimal string; positive = owed, negative = in credit.
     * @param  class-string<RendersAttachment>|null  $pdfRenderer  Builds the statement PDF at send time from `$pdfKey`.
     */
    public function __construct(
        public string $businessName,
        public ?string $businessEmail,
        public ?string $businessPhone,
        public string $customerName,
        public string $period,
        public string $closingBalance,
        public int $closingPoints,
        public ?string $pdfRenderer = null,
        public ?string $pdfKey = null,
        public string $filename = 'statement.pdf',
        public ?string $companyId = null,
    ) {}
}
