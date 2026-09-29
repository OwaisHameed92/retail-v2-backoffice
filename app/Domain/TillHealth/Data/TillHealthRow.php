<?php

namespace App\Domain\TillHealth\Data;

use App\Domain\TillHealth\Enums\HealthProblem;
use App\Domain\TillHealth\Enums\SyncState;
use App\Domain\TillHealth\Enums\TillState;
use Carbon\CarbonImmutable;

/**
 * One till's worked-out health (module 2.7): what `till_health` stores and the screens show.
 */
final readonly class TillHealthRow
{
    /**
     * @param  list<HealthProblem>  $problems
     */
    public function __construct(
        public TillSource $till,
        public TillState $state,
        public bool $isSyncTill,
        public SyncState $syncState,
        public ?CarbonImmutable $lastSeenAt,
        public ?CarbonImmutable $lastPushAt,
        public ?CarbonImmutable $lastPullAt,
        public ?string $appVersion,
        public bool $appOutdated,
        public bool $clockSkewed,
        public ?int $pendingSyncRows,
        public ?int $pendingSyncRowsPrevious,
        public array $problems,
    ) {}

    public function has(HealthProblem $problem): bool
    {
        return in_array($problem, $this->problems, true);
    }

    /**
     * The `till_health` columns (without id and timestamps).
     *
     * @return array<string, mixed>
     */
    public function columns(CarbonImmutable $checkedAt): array
    {
        $till = $this->till;

        return [
            'company_id' => $till->companyId,
            'branch_id' => $till->branchId,
            'register_id' => $till->registerId,
            'licence_id' => $till->licenceId,
            'state' => $this->state->value,
            'is_sync_till' => $this->isSyncTill,
            'sync_state' => $this->syncState->value,
            'last_seen_at' => self::sql($this->lastSeenAt),
            'last_validated_at' => self::sql($till->lastValidatedAt),
            'last_push_at' => self::sql($this->lastPushAt),
            'last_pull_at' => self::sql($this->lastPullAt),
            'app_version' => $this->appVersion === null ? null : mb_substr($this->appVersion, 0, 50),
            'app_outdated' => $this->appOutdated,
            'contract_version' => $till->contractVersion,
            'install_id' => $till->installId === null ? null : mb_substr($till->installId, 0, 64),
            'device_name' => $till->deviceName === null ? null : mb_substr($till->deviceName, 0, 100),
            'clock_skew_seconds' => $till->clockSkewSeconds,
            'clock_skewed' => $this->clockSkewed,
            'pending_sync_rows' => $this->pendingSyncRows,
            'pending_sync_rows_previous' => $this->pendingSyncRowsPrevious,
            'diagnostics_at' => self::sql($till->diagnosticsAt),
            'lock_locked' => $till->lockLocked,
            'lock_reason' => $till->lockReason,
            'problem_count' => count($this->problems),
            'checked_at' => self::sql($checkedAt),
        ];
    }

    public static function sql(?CarbonImmutable $at): ?string
    {
        return $at?->utc()->format('Y-m-d H:i:s');
    }
}
