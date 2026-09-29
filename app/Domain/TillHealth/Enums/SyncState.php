<?php

namespace App\Domain\TillHealth\Enums;

/**
 * A shop's cloud sync (module 2.7), from `sync_branch_status` and the main till's validate diagnostics.
 *
 * - notLinked: the shop has never said hello, pushed or pulled (no dashboard, or not connected yet);
 * - healthy: syncing, no recent error;
 * - failing: the last error came at or after the last push and within `sync_failing_hours` (a rejected row, a
 *   refused key…): the till retries the same rows until it is fixed;
 * - stalled: the main till is on and online (validated recently) but has not synced for `sync_stalled_hours`, or
 *   the rows it has waiting grew between two check-ins.
 */
enum SyncState: string
{
    case NotLinked = 'notLinked';
    case Healthy = 'healthy';
    case Failing = 'failing';
    case Stalled = 'stalled';

    public function label(): string
    {
        return match ($this) {
            self::NotLinked => 'Not linked',
            self::Healthy => 'Syncing',
            self::Failing => 'Failing',
            self::Stalled => 'Stalled',
        };
    }

    public function isProblem(): bool
    {
        return $this === self::Failing || $this === self::Stalled;
    }
}
