<?php

namespace App\Domain\TillData\Sync;

/**
 * Who is pushing: the company, the sending branch (the X-SSPOS-Branch-Id the key belongs to) and that branch's
 * registers. `now` is one UTC timestamp for the whole batch (synced_at, applied_at).
 */
final readonly class SyncContext
{
    /**
     * @param  array<string, true>  $registerIds  the sending branch's registers (soft-deleted included)
     */
    public function __construct(
        public string $companyId,
        public string $branchId,
        public array $registerIds,
        public string $now,
    ) {}

    public function ownsRegister(string $registerId): bool
    {
        return isset($this->registerIds[$registerId]);
    }
}
