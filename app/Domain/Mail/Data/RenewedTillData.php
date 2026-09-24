<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

final readonly class RenewedTillData
{
    public function __construct(
        public string $branchName,
        public string $tillName,
        public CarbonInterface $expiresAt,
    ) {}
}
