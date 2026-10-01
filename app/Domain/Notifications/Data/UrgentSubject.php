<?php

namespace App\Domain\Notifications\Data;

use App\Domain\Notifications\Enums\AlertType;
use Carbon\CarbonImmutable;

/**
 * One urgent problem an owner may be emailed about (module 7.8): an open (or just cleared) Till health alert of a
 * till's licence. `key` ("<licence alert type>|<licence id>") is what de-duplication counts.
 */
final readonly class UrgentSubject
{
    public function __construct(
        public string $key,
        public string $companyId,
        public string $businessName,
        public AlertType $type,
        public string $problem,
        public ?string $branchId,
        public string $shopName,
        public ?string $tillName,
        public ?string $summary,
        public CarbonImmutable $since,
        public ?CarbonImmutable $resolvedAt = null,
        /** Cleared because the till is fine again, not because the licence or business stopped trading. */
        public bool $genuinelyResolved = true,
    ) {}

    public static function key(string $licenceAlertType, string $licenceId): string
    {
        return $licenceAlertType.'|'.$licenceId;
    }
}
