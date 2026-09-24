<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Licensing\Models\Licence;
use Carbon\CarbonImmutable;
use Countable;

/**
 * Result of RenewLicence / RenewCompanyLicences: the licences, their new expiry and whether the owners were emailed.
 */
final readonly class RenewedLicences implements Countable
{
    /**
     * @param  list<Licence>  $licences
     */
    public function __construct(
        public array $licences,
        public CarbonImmutable $expiresAt,
        public int $ownersEmailed = 0,
    ) {}

    public function count(): int
    {
        return count($this->licences);
    }
}
