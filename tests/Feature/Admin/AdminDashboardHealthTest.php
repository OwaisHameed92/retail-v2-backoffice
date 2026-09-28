<?php

use App\Domain\Admin\Queries\Dashboard\SystemHealth;
use App\Domain\Shared\Support\SchedulerHeartbeat;
use Carbon\CarbonImmutable;

function healthOf(string $key): array
{
    return collect(SystemHealth::check(CarbonImmutable::now()))->firstWhere('key', $key);
}

test('system health reports only what it can check and labels the rest', function () {
    expect(healthOf('database'))->toMatchArray(['state' => 'healthy', 'detail' => 'Reachable'])
        ->and(healthOf('queue'))->toMatchArray(['state' => 'healthy', 'detail' => 'Run straight away (sync)'])
        ->and(healthOf('scheduler'))->toMatchArray(['state' => 'unknown', 'detail' => 'No run recorded yet'])
        ->and(healthOf('signing'))->toMatchArray(['state' => 'down', 'detail' => 'No active key'])
        ->and(healthOf('email'))->toMatchArray(['state' => 'unknown', 'detail' => 'Not monitored yet'])
        ->and(healthOf('tills'))->toMatchArray(['state' => 'unknown', 'detail' => 'Arrives with module 2.7']);
});

test('the scheduler heartbeat shows the last run and goes down when it stops', function () {
    SchedulerHeartbeat::beat();
    expect(healthOf('scheduler'))->toMatchArray(['state' => 'healthy', 'detail' => 'Last run just now']);

    $this->travel(10)->minutes();
    expect(healthOf('scheduler'))->toMatchArray(['state' => 'down', 'detail' => 'Last run 10 min ago']);
});

test('the database queue shows its backlog and failed jobs', function () {
    config(['queue.default' => 'database']);
    DB::table('failed_jobs')->insert(['uuid' => 'x-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

    expect(healthOf('queue'))->toMatchArray(['state' => 'degraded', 'detail' => '0 waiting, 1 failed']);
});
