<?php

namespace App\Domain\Mail\Data;

/**
 * One or more licence keys sent to an owner after they were created or replaced in the admin area (module 1.3).
 * The keys are secrets: shown in this email only, never logged.
 */
final readonly class LicenceKeyData
{
    /**
     * @param  list<TillKeyData>  $tills  One entry per till, with its plain licence key.
     * @param  bool  $replacesOldKey  The keys replace older ones that no longer work.
     */
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public array $tills,
        public bool $replacesOldKey = false,
        public ?string $companyId = null,
    ) {}
}
