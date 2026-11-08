<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Models\FailedJob;
use App\Domain\Shared\Support\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The health strip of the admin "Sync & jobs" page: scheduler, queue backlog, failed jobs and sync problems in the
 * last 24 hours. States: ok | warning | problem | unknown (green, amber, red, grey).
 *
 * @phpstan-type HealthItem array{key: string, label: string, state: string, value: string, detail: string, at: string|null, atLabel: string|null}
 */
final class OperationsHealth
{
    /** The scheduler beats every minute; no beat for longer than this means it stopped. */
    public const SCHEDULER_STALE_MINUTES = 3;

    /** The oldest waiting job: amber from this age, red from {@see self::QUEUE_PROBLEM_MINUTES}. */
    public const QUEUE_WARNING_MINUTES = 5;

    public const QUEUE_PROBLEM_MINUTES = 30;

    /** Failed jobs in 24 hours: amber from 1, red from this many. */
    public const FAILED_PROBLEM = 10;

    /**
     * @param  array{refused: int, unknown: int, conflict: int}  $syncLast24h  from SyncProblemList::compute()
     * @return list<HealthItem>
     */
    public static function compute(CarbonImmutable $now, array $syncLast24h): array
    {
        return [self::scheduler($now), self::queue($now), self::failed($now), self::sync($syncLast24h)];
    }

    /**
     * @return HealthItem
     */
    private static function scheduler(CarbonImmutable $now): array
    {
        $last = SchedulerHeartbeat::lastRun();
        $item = fn (string $state, string $value, string $detail) => ['key' => 'scheduler', 'label' => 'Scheduler', 'state' => $state,
            'value' => $value, 'detail' => $detail, 'at' => $last?->utc()->toIso8601ZuluString(), 'atLabel' => 'Last heartbeat'];

        if ($last === null) {
            return $item('problem', 'Not running', 'No heartbeat recorded. Check the cron entry for schedule:run.');
        }

        return $last->lessThan($now->subMinutes(self::SCHEDULER_STALE_MINUTES))
            ? $item('problem', 'Stopped', 'No heartbeat for more than '.self::SCHEDULER_STALE_MINUTES.' minutes.')
            : $item('ok', 'Running', 'Heartbeat every minute.');
    }

    /**
     * @return HealthItem
     */
    private static function queue(CarbonImmutable $now): array
    {
        $queue = QueueBacklog::compute($now);
        $item = fn (string $state, string $value, string $detail, ?string $at = null) => ['key' => 'queue', 'label' => 'Queue backlog',
            'state' => $state, 'value' => $value, 'detail' => $detail, 'at' => $at, 'atLabel' => $at === null ? null : 'Oldest queued'];

        if ($queue['driver'] === 'sync') {
            return $item('ok', 'No queue', 'Jobs run straight away (sync driver).');
        }

        if (! $queue['readable'] || $queue['waiting'] === null) {
            return $item('unknown', 'Not readable', "The {$queue['driver']} queue could not be read.");
        }

        $waiting = $queue['waiting'];
        $value = number_format($waiting).' waiting';
        $running = $queue['running'] === null ? '' : number_format($queue['running']).' running now';

        if ($waiting === 0 || $queue['oldestAt'] === null) {
            return $item('ok', $value, $running === '' ? 'Nothing waiting.' : "Nothing waiting, {$running}.");
        }

        $minutes = (int) floor(CarbonImmutable::parse($queue['oldestAt'])->diffInMinutes($now));
        $state = match (true) {
            $minutes >= self::QUEUE_PROBLEM_MINUTES => 'problem',
            $minutes >= self::QUEUE_WARNING_MINUTES => 'warning',
            default => 'ok',
        };

        return $item($state, $value, $running === '' ? 'Jobs are waiting for a worker.' : ucfirst($running).'.', $queue['oldestAt']);
    }

    /**
     * @return HealthItem
     */
    private static function failed(CarbonImmutable $now): array
    {
        try {
            $recent = FailedJob::query()->where('failed_at', '>=', $now->subDay()->utc()->format('Y-m-d H:i:s'))->count();
            $total = FailedJob::query()->count();
            $latest = FailedJob::query()->max('failed_at');
        } catch (Throwable) {
            return ['key' => 'failed', 'label' => 'Failed jobs (24 h)', 'state' => 'unknown', 'value' => 'Not readable', 'detail' => 'The failed jobs table could not be read.', 'at' => null, 'atLabel' => null];
        }

        return [
            'key' => 'failed',
            'label' => 'Failed jobs (24 h)',
            'state' => match (true) {
                $recent >= self::FAILED_PROBLEM => 'problem',
                $recent > 0 => 'warning',
                default => 'ok',
            },
            'value' => number_format($recent),
            'detail' => $total === 0 ? 'None in the list.' : number_format($total).' in the list in all.',
            'at' => $total === 0 || $latest === null ? null : CarbonImmutable::parse((string) $latest, 'UTC')->toIso8601ZuluString(),
            'atLabel' => $total === 0 ? null : 'Last failure',
        ];
    }

    /**
     * @param  array{refused: int, unknown: int, conflict: int}  $last24h
     * @return HealthItem
     */
    private static function sync(array $last24h): array
    {
        $total = $last24h['refused'] + $last24h['unknown'] + $last24h['conflict'];
        $parts = array_filter([
            $last24h['refused'] > 0 ? $last24h['refused'].' refused '.($last24h['refused'] === 1 ? 'push' : 'pushes') : null,
            $last24h['unknown'] > 0 ? $last24h['unknown'].' unknown '.($last24h['unknown'] === 1 ? 'entity' : 'entities') : null,
            $last24h['conflict'] > 0 ? $last24h['conflict'].' with open conflicts' : null,
        ]);

        return [
            'key' => 'sync',
            'label' => 'Sync problems (24 h)',
            'state' => $last24h['refused'] > 0 ? 'problem' : ($total > 0 ? 'warning' : 'ok'),
            'value' => number_format($total),
            'detail' => $parts === [] ? 'Every till push accepted.' : ucfirst(implode(', ', $parts)).'.',
            'at' => null,
            'atLabel' => null,
        ];
    }
}
