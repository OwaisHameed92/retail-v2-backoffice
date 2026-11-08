<?php

namespace App\Domain\Operations\Support;

use App\Domain\Operations\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records every scheduled task run in `scheduled_task_runs` from the scheduler's own events, so routes/console.php
 * needs no per-task change (admin "Sync & jobs"). It must never break the scheduler: every failure here is swallowed
 * and at most reported.
 *
 * Laravel's order: Starting → Finished (exit code set) → Failed when the command exited non-zero or a callback threw
 * (a throwing callback skips Finished). Background tasks finish in another process (ScheduledBackgroundTaskFinished).
 */
final class ScheduledTaskRecorder
{
    /** @var array<int, string> spl_object_id of the scheduled event => run id */
    private array $running = [];

    public static function register(): void
    {
        app()->singleton(self::class);

        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $e) => app(self::class)->starting($e));
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => app(self::class)->finished($e));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => app(self::class)->failed($e));
        Event::listen(ScheduledBackgroundTaskFinished::class, fn (ScheduledBackgroundTaskFinished $e) => app(self::class)->backgroundFinished($e));
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->safely(function () use ($event) {
            $run = ScheduledTaskRun::query()->create([
                'task' => ScheduledTaskName::of($event->task),
                'expression' => self::expression($event->task),
                'started_at' => CarbonImmutable::now('UTC'),
            ]);
            $this->running[spl_object_id($event->task)] = $run->id;
        });
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        if ($event->task->runInBackground) {
            return;
        }

        $this->safely(function () use ($event) {
            $run = $this->runOf($event->task);
            $exitCode = $event->task->exitCode === null ? 0 : (int) $event->task->exitCode;

            $run?->fill([
                'finished_at' => CarbonImmutable::now('UTC'),
                'duration_ms' => self::milliseconds($event->runtime),
                'succeeded' => $exitCode === 0,
                'exit_code' => $exitCode,
                'error' => $exitCode === 0 ? null : "Exit code {$exitCode}",
            ])->save();
        });
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->safely(function () use ($event) {
            $run = $this->runOf($event->task);
            unset($this->running[spl_object_id($event->task)]);

            if ($run === null) {
                return;
            }

            $now = CarbonImmutable::now('UTC');
            $exitCode = $event->task->exitCode === null ? 1 : (int) $event->task->exitCode;
            $message = $event->exception->getMessage();

            $run->fill([
                'finished_at' => $run->finished_at ?? $now,
                'duration_ms' => $run->duration_ms ?? self::milliseconds($run->started_at->diffInMilliseconds($now) / 1000),
                'succeeded' => false,
                'exit_code' => $exitCode === 0 ? 1 : $exitCode,
                // "Scheduled command [<php> artisan x] failed with exit code [1]." says nothing more than the exit code.
                'error' => str_starts_with($message, 'Scheduled command [')
                    ? 'Exit code '.($exitCode === 0 ? 1 : $exitCode)
                    : Str::limit(class_basename($event->exception).': '.SecretText::firstLine($message, 440), 500, ''),
            ])->save();
        });
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->safely(function () use ($event) {
            $run = ScheduledTaskRun::query()
                ->where('task', ScheduledTaskName::of($event->task))
                ->where('expression', self::expression($event->task))
                ->whereNull('finished_at')
                ->latest('started_at')
                ->first();
            $exitCode = $event->task->exitCode === null ? 0 : (int) $event->task->exitCode;
            $now = CarbonImmutable::now('UTC');

            $run?->fill([
                'finished_at' => $now,
                'duration_ms' => self::milliseconds($run->started_at->diffInMilliseconds($now) / 1000),
                'succeeded' => $exitCode === 0,
                'exit_code' => $exitCode,
                'error' => $exitCode === 0 ? null : "Exit code {$exitCode}",
            ])->save();
        });
    }

    private function runOf(ScheduledEvent $task): ?ScheduledTaskRun
    {
        $id = $this->running[spl_object_id($task)] ?? null;

        return $id === null ? null : ScheduledTaskRun::query()->find($id);
    }

    private static function expression(ScheduledEvent $task): string
    {
        return Str::limit((string) $task->expression, 64, '');
    }

    private static function milliseconds(float $seconds): int
    {
        return (int) max(0, min(4_294_967_295, round($seconds * 1000)));
    }

    private function safely(Closure $record): void
    {
        try {
            $record();
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
                // Recording must never stop the scheduler.
            }
        }
    }
}
