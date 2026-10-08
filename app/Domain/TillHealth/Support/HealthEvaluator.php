<?php

namespace App\Domain\TillHealth\Support;

use App\Domain\Shared\Country\TillProfile;
use App\Domain\TillHealth\Data\BranchHealthRow;
use App\Domain\TillHealth\Data\SyncSource;
use App\Domain\TillHealth\Data\TillHealthRow;
use App\Domain\TillHealth\Data\TillSource;
use App\Domain\TillHealth\Enums\HealthProblem;
use App\Domain\TillHealth\Enums\SyncState;
use App\Domain\TillHealth\Enums\TillState;
use Carbon\CarbonImmutable;

/**
 * Works out one shop's health from its tills, their licences' check-ins and its `sync_branch_status` (module 2.7).
 * Pure: no queries. The rules are in config/till-health.php and docs/DECISIONS.md "Till health".
 *
 * @phpstan-type Previous array{pending: int|null, previous: int|null, diagnosticsAt: CarbonImmutable|null}
 */
final readonly class HealthEvaluator
{
    /** Errors that say "try again shortly", not that sync is broken. */
    private const TRANSIENT_ERRORS = ['server.busy', 'rate.limited', 'request.in_progress'];

    public function __construct(private HealthThresholds $thresholds, private CarbonImmutable $now) {}

    /**
     * @param  list<TillSource>  $tills  The shop's active tills.
     * @param  array<string, Previous>  $previous  Register id → the backlog kept at the last refresh.
     * @return array{branch: BranchHealthRow, tills: list<TillHealthRow>}
     */
    public function branch(string $companyId, string $branchId, array $tills, ?SyncSource $sync, array $previous = []): array
    {
        $syncTillId = $this->syncTillId($tills, $sync);
        $backlog = [];

        foreach ($tills as $till) {
            $backlog[$till->registerId] = $this->backlog($till, $previous[$till->registerId] ?? null);
        }

        $syncTill = null;

        foreach ($tills as $till) {
            $syncTill = $till->registerId === $syncTillId ? $till : $syncTill;
        }

        $syncState = $this->syncState($sync, $syncTill, $syncTill === null ? false : $backlog[$syncTill->registerId]['growing']);
        $rows = array_map(fn (TillSource $till) => $this->till($till, $till->registerId === $syncTillId ? $sync : null, $syncState, $backlog[$till->registerId]), $tills);

        return ['branch' => $this->summarise($companyId, $branchId, $rows, $sync, $syncState), 'tills' => $rows];
    }

    /**
     * @param  array{pending: int|null, previous: int|null, growing: bool}  $backlog
     */
    private function till(TillSource $till, ?SyncSource $sync, SyncState $branchSync, array $backlog): TillHealthRow
    {
        $isSyncTill = $sync !== null;
        $syncContact = $sync?->lastContactAt();
        $lastSeen = self::latest($till->lastCheckInAt, $syncContact);
        $state = $this->state($till, $isSyncTill ? $syncContact : null, $lastSeen);
        $version = $till->appVersion ?? $sync?->lastAppVersion;
        $activated = $till->isActivated();
        $outdated = $activated && $version !== null && TillProfile::compareAppVersion($version, $this->thresholds->minimumAppVersion) < 0;
        $skewed = $activated && $till->clockSkewSeconds !== null && abs($till->clockSkewSeconds) > $this->thresholds->clockSkewSeconds;
        $tillSync = $isSyncTill ? $branchSync : SyncState::NotLinked;

        $problems = array_values(array_filter([
            $state === TillState::Offline ? HealthProblem::Offline : null,
            $outdated ? HealthProblem::OldVersion : null,
            $tillSync === SyncState::Failing ? HealthProblem::SyncFailing : null,
            $tillSync === SyncState::Stalled ? HealthProblem::SyncStalled : null,
            $skewed ? HealthProblem::ClockSkew : null,
        ]));

        return new TillHealthRow(
            till: $till,
            state: $state,
            isSyncTill: $isSyncTill,
            syncState: $tillSync,
            lastSeenAt: $lastSeen,
            lastPushAt: $sync?->lastPushAt,
            lastPullAt: $sync?->lastPullAt,
            appVersion: $version,
            appOutdated: $outdated,
            clockSkewed: $skewed,
            pendingSyncRows: $backlog['pending'],
            pendingSyncRowsPrevious: $backlog['previous'],
            problems: $problems,
        );
    }

    private function state(TillSource $till, ?CarbonImmutable $syncContact, ?CarbonImmutable $lastSeen): TillState
    {
        if (! $till->isActivated() || $lastSeen === null) {
            return TillState::NotActivated;
        }

        $t = $this->thresholds;
        $validatedRecently = $till->lastCheckInAt !== null && $till->lastCheckInAt->greaterThanOrEqualTo($this->now->subHours($t->validateOnlineHours));

        if ($syncContact !== null) {
            if ($syncContact->greaterThanOrEqualTo($this->now->subMinutes($t->syncOnlineMinutes))) {
                return TillState::Online;
            }

            return $syncContact->lessThan($this->now->subHours($t->syncOfflineHours)) && ! $validatedRecently ? TillState::Offline : TillState::Stale;
        }

        if ($validatedRecently) {
            return TillState::Online;
        }

        return $lastSeen->lessThan($this->now->subHours($t->validateOfflineHours)) ? TillState::Offline : TillState::Stale;
    }

    private function syncState(?SyncSource $sync, ?TillSource $syncTill, bool $growing): SyncState
    {
        $contact = $sync?->lastContactAt();

        if ($sync === null || $contact === null) {
            return SyncState::NotLinked;
        }

        $t = $this->thresholds;
        $error = $sync->lastErrorAt;
        // A rejected row comes with a 200 push: only a later clean push clears it. Other errors: any later call.
        $since = $sync->lastErrorCode === 'row.invalid' ? $sync->lastPushAt : self::latest($sync->lastPushAt, $sync->lastPullAt);

        if ($error !== null
            && ! in_array($sync->lastErrorCode, self::TRANSIENT_ERRORS, true)
            && $error->greaterThanOrEqualTo($this->now->subHours($t->syncFailingHours))
            && ($since === null || $error->greaterThanOrEqualTo($since))) {
            return SyncState::Failing;
        }

        $alive = $syncTill?->lastCheckInAt !== null && $syncTill->lastCheckInAt->greaterThanOrEqualTo($this->now->subHours($t->validateOnlineHours));
        $silent = $contact->lessThan($this->now->subHours($t->syncStalledHours));

        return ($alive && $silent) || $growing ? SyncState::Stalled : SyncState::Healthy;
    }

    /**
     * The till's waiting rows now and at the check-in before (validate `diagnostics.pendingSyncRows`).
     *
     * @param  Previous|null  $previous
     * @return array{pending: int|null, previous: int|null, growing: bool}
     */
    private function backlog(TillSource $till, ?array $previous): array
    {
        $pending = $till->pendingSyncRows;
        $before = $previous === null ? null : (
            $till->diagnosticsAt !== null && ($previous['diagnosticsAt'] === null || $till->diagnosticsAt->greaterThan($previous['diagnosticsAt']))
                ? $previous['pending']
                : $previous['previous']
        );

        return ['pending' => $pending, 'previous' => $before, 'growing' => $pending !== null && $before !== null && $before > 0 && $pending > $before];
    }

    /**
     * @param  list<TillSource>  $tills
     */
    private function syncTillId(array $tills, ?SyncSource $sync): ?string
    {
        if ($sync === null || ! $sync->isLinked()) {
            return null;
        }

        $ids = array_map(fn (TillSource $till) => $till->registerId, $tills);

        if ($sync->lastRegisterId !== null && in_array($sync->lastRegisterId, $ids, true)) {
            return $sync->lastRegisterId;
        }

        foreach ($tills as $till) {
            if ($till->isMainTill) {
                return $till->registerId;
            }
        }

        return null;
    }

    /**
     * @param  list<TillHealthRow>  $rows
     */
    private function summarise(string $companyId, string $branchId, array $rows, ?SyncSource $sync, SyncState $syncState): BranchHealthRow
    {
        $activated = array_values(array_filter($rows, fn (TillHealthRow $row) => $row->state !== TillState::NotActivated));
        $online = count(array_filter($activated, fn (TillHealthRow $row) => $row->state === TillState::Online));
        $offline = count(array_filter($activated, fn (TillHealthRow $row) => $row->state === TillState::Offline));

        $state = match (true) {
            $activated === [] => TillState::NotActivated,
            $online > 0 => TillState::Online,
            $offline === count($activated) => TillState::Offline,
            default => TillState::Stale,
        };

        $lastContact = array_reduce($rows, fn (?CarbonImmutable $max, TillHealthRow $row) => self::latest($max, $row->lastSeenAt));

        return new BranchHealthRow($companyId, $branchId, $state, $syncState, self::latest($lastContact, $sync?->lastContactAt()), $sync, count($rows), $online, $offline);
    }

    private static function latest(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->greaterThan($b) ? $a : $b;
    }
}
