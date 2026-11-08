<?php

namespace App\Domain\Operations\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Jobs waiting on the default queue connection: how many are ready and not picked up, how old the oldest one is, and
 * how many a worker is running now. Exact for the `database` driver (production); `redis` gives a size only; `sync`
 * runs jobs straight away.
 */
final class QueueBacklog
{
    /**
     * @return array{driver: string, readable: bool, waiting: int|null, running: int|null, delayed: int|null, oldestAt: string|null}
     */
    public static function compute(CarbonImmutable $now): array
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver");
        $result = ['driver' => $driver, 'readable' => true, 'waiting' => null, 'running' => null, 'delayed' => null, 'oldestAt' => null];

        try {
            if ($driver === 'database') {
                $table = DB::table((string) config("queue.connections.{$connection}.table", 'jobs'));
                $timestamp = $now->getTimestamp();
                $ready = (clone $table)->whereNull('reserved_at')->where('available_at', '<=', $timestamp);
                $oldest = (clone $ready)->min('available_at');

                return [...$result,
                    'waiting' => (clone $ready)->count(),
                    'running' => (clone $table)->whereNotNull('reserved_at')->count(),
                    'delayed' => (clone $table)->whereNull('reserved_at')->where('available_at', '>', $timestamp)->count(),
                    'oldestAt' => $oldest === null ? null : CarbonImmutable::createFromTimestampUTC((int) $oldest)->toIso8601ZuluString(),
                ];
            }

            if ($driver === 'redis') {
                return [...$result, 'waiting' => Queue::connection($connection)->size((string) config("queue.connections.{$connection}.queue", 'default'))];
            }
        } catch (Throwable) {
            return [...$result, 'readable' => false];
        }

        return $result;
    }
}
