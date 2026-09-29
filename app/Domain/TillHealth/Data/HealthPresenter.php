<?php

namespace App\Domain\TillHealth\Data;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\TillHealth\Enums\HealthProblem;
use App\Domain\TillHealth\Enums\SyncState;
use App\Domain\TillHealth\Enums\TillState;
use App\Domain\TillHealth\Models\BranchHealth;
use App\Domain\TillHealth\Models\TillHealth;
use Carbon\CarbonImmutable;

/**
 * Till and shop health as the React screens read it (module 2.7), from a live evaluation (one business) or a
 * stored row (the admin list). Matches `resources/js/components/till-health/types.ts`. The install id is shown in
 * full only to admins; the tenant portal gets `installId: null`.
 *
 * @phpstan-type Problem array{value: string, label: string}
 */
final class HealthPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function till(TillHealthRow|TillHealth $row, bool $admin = true): array
    {
        if ($row instanceof TillHealthRow) {
            $till = $row->till;

            return self::tillArray($row->state, $row->syncState, $row->appOutdated, $row->clockSkewed, [
                'registerId' => $till->registerId,
                'branchId' => $till->branchId,
                'licenceId' => $till->licenceId,
                'isSyncTill' => $row->isSyncTill,
                'lastSeenAt' => self::date($row->lastSeenAt),
                'lastValidatedAt' => self::date($till->lastValidatedAt),
                'lastPushAt' => self::date($row->lastPushAt),
                'lastPullAt' => self::date($row->lastPullAt),
                'appVersion' => $row->appVersion,
                'contractVersion' => $till->contractVersion,
                'installId' => $admin ? $till->installId : null,
                'deviceName' => $till->deviceName,
                'clockSkewSeconds' => $till->clockSkewSeconds,
                'pendingSyncRows' => $row->pendingSyncRows,
                'lock' => $till->lockLocked === null ? null : ['locked' => $till->lockLocked, 'reason' => $till->lockReason],
            ]);
        }

        return self::tillArray($row->state, $row->sync_state, $row->app_outdated, $row->clock_skewed, [
            'registerId' => $row->register_id,
            'branchId' => $row->branch_id,
            'licenceId' => $row->licence_id,
            'isSyncTill' => $row->is_sync_till,
            'lastSeenAt' => self::date($row->last_seen_at),
            'lastValidatedAt' => self::date($row->last_validated_at),
            'lastPushAt' => self::date($row->last_push_at),
            'lastPullAt' => self::date($row->last_pull_at),
            'appVersion' => $row->app_version,
            'contractVersion' => $row->contract_version,
            'installId' => $admin ? $row->install_id : null,
            'deviceName' => $row->device_name,
            'clockSkewSeconds' => $row->clock_skew_seconds,
            'pendingSyncRows' => $row->pending_sync_rows,
            'lock' => $row->lock_locked === null ? null : ['locked' => $row->lock_locked, 'reason' => $row->lock_reason],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function branch(BranchHealthRow|BranchHealth $row): array
    {
        if ($row instanceof BranchHealthRow) {
            $sync = $row->sync;

            return self::branchArray($row->state, $row->syncState, [
                'branchId' => $row->branchId,
                'lastContactAt' => self::date($row->lastContactAt),
                'lastSyncAt' => self::date($sync?->lastContactAt()),
                'lastPushAt' => self::date($sync?->lastPushAt),
                'lastPullAt' => self::date($sync?->lastPullAt),
                'lastError' => $sync?->lastErrorAt === null ? null : ['code' => $sync->lastErrorCode, 'message' => $sync->lastErrorMessage, 'at' => self::date($sync->lastErrorAt)],
                'tills' => $row->tills,
                'tillsOnline' => $row->tillsOnline,
                'tillsOffline' => $row->tillsOffline,
            ]);
        }

        return self::branchArray($row->state, $row->sync_state, [
            'branchId' => $row->branch_id,
            'lastContactAt' => self::date($row->last_contact_at),
            'lastSyncAt' => self::date($row->last_sync_at),
            'lastPushAt' => self::date($row->last_push_at),
            'lastPullAt' => self::date($row->last_pull_at),
            'lastError' => $row->last_error_at === null ? null : ['code' => $row->last_error_code, 'message' => $row->last_error_message, 'at' => self::date($row->last_error_at)],
            'tills' => $row->tills,
            'tillsOnline' => $row->tills_online,
            'tillsOffline' => $row->tills_offline,
        ]);
    }

    /**
     * @return list<Problem>
     */
    public static function problems(TillState $state, SyncState $sync, bool $outdated, bool $skewed): array
    {
        $problems = array_filter([
            $state === TillState::Offline ? HealthProblem::Offline : null,
            $outdated ? HealthProblem::OldVersion : null,
            $sync === SyncState::Failing ? HealthProblem::SyncFailing : null,
            $sync === SyncState::Stalled ? HealthProblem::SyncStalled : null,
            $skewed ? HealthProblem::ClockSkew : null,
        ]);

        return array_values(array_map(fn (HealthProblem $problem) => ['value' => $problem->value, 'label' => $problem->label()], $problems));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function tillArray(TillState $state, SyncState $sync, bool $outdated, bool $skewed, array $fields): array
    {
        return [
            ...$fields,
            'state' => $state->value,
            'stateLabel' => $state->label(),
            'syncState' => $sync->value,
            'syncStateLabel' => $sync->label(),
            'appOutdated' => $outdated,
            'clockSkewed' => $skewed,
            'problems' => self::problems($state, $sync, $outdated, $skewed),
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function branchArray(TillState $state, SyncState $sync, array $fields): array
    {
        return [...$fields, 'state' => $state->value, 'stateLabel' => $state->label(), 'syncState' => $sync->value, 'syncStateLabel' => $sync->label()];
    }

    private static function date(?CarbonImmutable $at): ?string
    {
        return $at === null ? null : ApiDate::format($at);
    }
}
