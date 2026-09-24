<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

final readonly class AccountReactivatedData
{
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public int $tillCount,
        public ?CarbonInterface $activeUntil = null,
        public ?string $companyId = null,
    ) {}
}
