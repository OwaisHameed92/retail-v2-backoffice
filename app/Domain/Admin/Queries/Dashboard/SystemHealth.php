<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Shared\Support\SchedulerHeartbeat;
use App\Domain\TillHealth\Queries\TillHealthSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Only what the portal can really check about itself. Anything else says "Not monitored yet". "Till sync" reads
 * the Till health rows (module 2.7). States: healthy | degraded | down | unknown.
 *
 * @phpstan-type Health array{key: string, name: string, state: string, detail: string}
 */
final class SystemHealth
{
    /** Waiting jobs above this count as a backlog. */
    public const QUEUE_BACKLOG = 100;

    /** The scheduler beats every minute; older than this means it stopped. */
    public const SCHEDULER_STALE_MINUTES = 5;

    /**
     * @return list<Health>
     */
    public static function check(CarbonImmutable $now): array
    {
        return [
            self::database(),
            self::queue(),
            self::scheduler($now),
            self::signingKey(),
            ['key' => 'email', 'name' => 'Email delivery', 'state' => 'unknown', 'detail' => 'Not monitored yet'],
            self::tills(),
        ];
    }

    /**
     * Module 2.7: degraded while any till is offline or a shop's sync is failing or stalled.
     *
     * @return Health
     */
    private static function tills(): array
    {
        $item = fn (string $state, string $detail) => ['key' => 'tills', 'name' => 'Till sync', 'state' => $state, 'detail' => $detail];

        try {
            $summary = TillHealthSummary::compute();
        } catch (Throwable) {
            return $item('unknown', 'Could not be read');
        }

        if ($summary['tills'] - $summary['notActivated'] === 0) {
            return $item('unknown', 'No tills reporting yet');
        }

        $problems = array_filter([
            $summary['offline'] > 0 ? "{$summary['offline']} offline" : null,
            $summary['sync'] > 0 ? "{$summary['sync']} sync ".($summary['sync'] === 1 ? 'problem' : 'problems') : null,
        ]);

        return $problems === [] ? $item('healthy', "{$summary['online']} online") : $item('degraded', implode(', ', $problems));
    }

    /**
     * @return Health
     */
    private static function database(): array
    {
        try {
            DB::select('select 1');

            return ['key' => 'database', 'name' => 'Database', 'state' => 'healthy', 'detail' => 'Reachable'];
        } catch (Throwable) {
            return ['key' => 'database', 'name' => 'Database', 'state' => 'down', 'detail' => 'Not reachable'];
        }
    }

    /**
     * @return Health
     */
    private static function queue(): array
    {
        $item = fn (string $state, string $detail) => ['key' => 'queue', 'name' => 'Background jobs', 'state' => $state, 'detail' => $detail];
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver");

        if ($driver === 'sync') {
            return $item('healthy', 'Run straight away (sync)');
        }

        if ($driver !== 'database') {
            return $item('unknown', 'Not monitored yet');
        }

        try {
            $waiting = DB::table((string) config("queue.connections.{$connection}.table", 'jobs'))->count();
            $failed = DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable) {
            return $item('unknown', 'Queue tables not found');
        }

        $detail = $waiting.' waiting'.($failed > 0 ? ", {$failed} failed" : '');

        return $item($waiting > self::QUEUE_BACKLOG || $failed > 0 ? 'degraded' : 'healthy', $detail);
    }

    /**
     * @return Health
     */
    private static function scheduler(CarbonImmutable $now): array
    {
        $last = SchedulerHeartbeat::lastRun();

        if ($last === null) {
            return ['key' => 'scheduler', 'name' => 'Scheduler', 'state' => 'unknown', 'detail' => 'No run recorded yet'];
        }

        $stale = $last->lessThan($now->subMinutes(self::SCHEDULER_STALE_MINUTES));

        return ['key' => 'scheduler', 'name' => 'Scheduler', 'state' => $stale ? 'down' : 'healthy', 'detail' => 'Last run '.self::ago($last, $now)];
    }

    private static function ago(CarbonImmutable $then, CarbonImmutable $now): string
    {
        $minutes = max(0, (int) floor($then->diffInMinutes($now)));

        return match (true) {
            $minutes < 1 => 'just now',
            $minutes < 120 => "{$minutes} min ago",
            $minutes < 2880 => intdiv($minutes, 60).' h ago',
            default => intdiv($minutes, 1440).' days ago',
        };
    }

    /**
     * @return Health
     */
    private static function signingKey(): array
    {
        $item = fn (string $state, string $detail) => ['key' => 'signing', 'name' => 'Licence signing key', 'state' => $state, 'detail' => $detail];

        try {
            $store = app(KeyStore::class);

            if (! $store->hasActive()) {
                return $item('down', 'No active key');
            }

            return $store->active()->signerCert !== null ? $item('healthy', 'Active and certified') : $item('degraded', 'Active, no signer certificate');
        } catch (Throwable) {
            return $item('down', 'Could not be read');
        }
    }
}
