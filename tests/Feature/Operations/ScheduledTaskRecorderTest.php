<?php

use App\Domain\Operations\Models\ScheduledTaskRun;
use App\Domain\Operations\Support\ScheduledTaskName;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

/*
 * Admin "Sync & jobs": every scheduled task run is recorded from the scheduler's events, and recording never breaks
 * the scheduler.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC'));
});

function commandTask(string $command = 'billing:run'): ScheduledEvent
{
    return (new ScheduledEvent(app(CacheEventMutex::class), "'/usr/bin/php8.4' 'artisan' {$command}"))->dailyAt('06:00');
}

test('a successful command run is recorded with its start, finish, duration and exit code', function () {
    $task = commandTask();

    event(new ScheduledTaskStarting($task));
    $this->travel(3)->seconds();
    $task->exitCode = 0;
    event(new ScheduledTaskFinished($task, 2.5));

    $run = ScheduledTaskRun::query()->sole();
    expect($run->task)->toBe('billing:run')
        ->and($run->expression)->toBe('0 6 * * *')
        ->and($run->started_at->toIso8601ZuluString())->toBe('2026-11-08T06:00:00Z')
        ->and($run->finished_at?->toIso8601ZuluString())->toBe('2026-11-08T06:00:03Z')
        ->and($run->duration_ms)->toBe(2500)
        ->and($run->succeeded)->toBeTrue()
        ->and($run->exit_code)->toBe(0)
        ->and($run->error)->toBeNull();
});

test('a command exiting non-zero is recorded as failed with its exit code', function () {
    $task = commandTask('licences:refresh');

    event(new ScheduledTaskStarting($task));
    $task->exitCode = 2;
    event(new ScheduledTaskFinished($task, 0.4));
    event(new ScheduledTaskFailed($task, new Exception("Scheduled command ['/usr/bin/php8.4' 'artisan' licences:refresh] failed with exit code [2].")));

    expect(ScheduledTaskRun::query()->sole())
        ->succeeded->toBeFalse()
        ->exit_code->toBe(2)
        ->duration_ms->toBe(400)
        ->error->toBe('Exit code 2');
});

test('a callback that throws is recorded as failed with the error, secrets removed', function () {
    $task = (new CallbackEvent(app(CacheEventMutex::class), fn () => null))->name('invoice-imports:purge-files')->everyMinute();

    event(new ScheduledTaskStarting($task));
    $this->travel(2)->seconds();
    event(new ScheduledTaskFailed($task, new RuntimeException('Disk refused: secret=abc123 for the purge')));

    $run = ScheduledTaskRun::query()->sole();
    expect($run->task)->toBe('invoice-imports:purge-files')
        ->and($run->succeeded)->toBeFalse()
        ->and($run->exit_code)->toBe(1)
        ->and($run->duration_ms)->toBe(2000)
        ->and($run->error)->toBe('RuntimeException: Disk refused: secret=[redacted] for the purge');
});

test('recording never throws: a broken table is reported and the scheduler carries on', function () {
    Exceptions::fake();
    Schema::drop('scheduled_task_runs');
    $task = commandTask();

    event(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    event(new ScheduledTaskFinished($task, 1.0));
    event(new ScheduledTaskFailed($task, new Exception('boom')));

    Exceptions::assertReported(QueryException::class);
});

test('task names drop the PHP binary and shorten class names, matching what the admin list shows', function () {
    expect(ScheduledTaskName::of(commandTask('billing:run')))->toBe('billing:run')
        ->and(ScheduledTaskName::of(commandTask("model:prune --model='App\\Domain\\Mail\\Models\\EmailLog' --model='App\\Domain\\Ai\\Models\\AiUsage'")))
        ->toBe('model:prune --model=EmailLog --model=AiUsage')
        ->and(ScheduledTaskName::of(commandTask('privacy:retention --apply')))->toBe('privacy:retention --apply')
        ->and(ScheduledTaskName::of(new ScheduledEvent(app(CacheEventMutex::class), '"C:\\php\\php.exe" "artisan" alerts:check')))->toBe('alerts:check');
});

test('old runs are pruned after 30 days', function () {
    ScheduledTaskRun::query()->create(['task' => 'billing:run', 'expression' => '0 6 * * *', 'started_at' => now()->subDays(31), 'succeeded' => true]);
    ScheduledTaskRun::query()->create(['task' => 'billing:run', 'expression' => '0 6 * * *', 'started_at' => now()->subDays(29), 'succeeded' => true]);

    $this->artisan('model:prune', ['--model' => [ScheduledTaskRun::class]])->assertSuccessful();

    expect(ScheduledTaskRun::query()->count())->toBe(1);
});
