<?php

namespace App\Domain\Mail\Data;

/**
 * Everything the welcome email needs. Built by trial approval (module 1.6) right after the keys are issued.
 */
final readonly class WelcomeTenantData
{
    /**
     * @param  list<TillKeyData>  $tills  One entry per till, with its plain licence key (shown once).
     * @param  int|null  $trialDays  Set when the account starts on a free trial.
     */
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public string $ownerEmail,
        public string $loginUrl,
        public array $tills,
        public ?int $trialDays = null,
        public ?string $companyId = null,
        /** Module 1.13: the portal Billing page where the owner sets up Direct Debit, and the days they have. */
        public ?string $billingUrl = null,
        public ?int $directDebitDays = null,
        /** OS-13: an online store only business (no tills, no keys): the portal page where it builds its store. Read
         * with `?? null` (a welcome email held before OS-13 has no such property). */
        public ?string $onlineStoreUrl = null,
    ) {}

    /** OS-13: the welcome email of an online store only business (no tills, no keys). */
    public function onlineStoreOnly(): bool
    {
        return ($this->onlineStoreUrl ?? null) !== null;
    }

    public function branchCount(): int
    {
        return count(array_unique(array_map(fn (TillKeyData $till) => $till->branchName, $this->tills)));
    }
}
