<?php

namespace App\Domain\TillHealth\Data;

use App\Domain\TillHealth\Enums\SyncState;
use App\Domain\TillHealth\Enums\TillState;
use Carbon\CarbonImmutable;

/**
 * One shop's worked-out health (module 2.7): its tills taken together plus its cloud sync.
 */
final readonly class BranchHealthRow
{
    public function __construct(
        public string $companyId,
        public string $branchId,
        public TillState $state,
        public SyncState $syncState,
        public ?CarbonImmutable $lastContactAt,
        public ?SyncSource $sync,
        public int $tills,
        public int $tillsOnline,
        public int $tillsOffline,
    ) {}

    /**
     * The `branch_health` columns (without id and timestamps).
     *
     * @return array<string, mixed>
     */
    public function columns(CarbonImmutable $checkedAt): array
    {
        return [
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'state' => $this->state->value,
            'sync_state' => $this->syncState->value,
            'last_contact_at' => TillHealthRow::sql($this->lastContactAt),
            'last_sync_at' => TillHealthRow::sql($this->sync?->lastContactAt()),
            'last_push_at' => TillHealthRow::sql($this->sync?->lastPushAt),
            'last_pull_at' => TillHealthRow::sql($this->sync?->lastPullAt),
            'last_error_at' => TillHealthRow::sql($this->sync?->lastErrorAt),
            'last_error_code' => $this->sync?->lastErrorCode,
            'last_error_message' => $this->sync?->lastErrorMessage,
            'tills' => $this->tills,
            'tills_online' => $this->tillsOnline,
            'tills_offline' => $this->tillsOffline,
            'checked_at' => TillHealthRow::sql($checkedAt),
        ];
    }
}
