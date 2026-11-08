<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Models\ScheduledTaskRun;
use App\Domain\Operations\Support\CronText;
use App\Domain\Operations\Support\ScheduledTaskLabel;
use App\Domain\Operations\Support\ScheduledTaskName;
use App\Domain\Operations\Support\SecretText;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every task in routes/console.php with its last recorded run (scheduled_task_runs), result, duration, runs and
 * failures over 7 days, and when it is next due.
 *
 * @phpstan-type LastRun array{startedAt: string, finishedAt: string|null, durationMs: int|null, state: string, error: string|null}
 * @phpstan-type TaskRow array{key: string, task: string, label: string, frequency: string, expression: string, timezone: string, nextDueAt: string|null, lastRun: LastRun|null, late: bool, runs7d: int, failed7d: int}
 */
final class ScheduledTaskList
{
    /** A run with no finish after this long is shown as "No finish recorded" (the process died or was killed). */
    public const UNFINISHED_HOURS = 6;

    /** A task whose last run started this long before its last due time counts as late. */
    public const LATE_GRACE_MINUTES = 2;

    /**
     * @return list<TaskRow>
     */
    public static function compute(CarbonImmutable $now): array
    {
        $events = self::events();

        if ($events === []) {
            return [];
        }

        $latest = self::latestRuns();
        $counts = DB::table('scheduled_task_runs')
            ->where('started_at', '>=', $now->subDays(7)->utc()->format('Y-m-d H:i:s'))
            ->groupBy('task', 'expression')
            ->selectRaw('task, expression, count(*) as runs, sum(case when succeeded = 0 then 1 else 0 end) as failed')
            ->get()
            ->keyBy(fn (object $row) => $row->task."\0".$row->expression);

        $rows = [];

        foreach ($events as $event) {
            $task = ScheduledTaskName::of($event);
            $expression = (string) $event->expression;
            $key = $task."\0".$expression;
            $timezone = self::timezone($event);
            $last = $latest[$key] ?? null;
            $count = $counts->get($key);

            $rows[] = [
                'key' => substr(hash('sha256', $key), 0, 16),
                'task' => $task,
                'label' => ScheduledTaskLabel::for($task),
                'frequency' => CronText::describe($expression),
                'expression' => $expression,
                'timezone' => $timezone,
                'nextDueAt' => self::nextDue($event),
                'lastRun' => $last === null ? null : self::lastRun($last, $now),
                'late' => $last !== null && self::isLate($last, $expression, $timezone, $now),
                'runs7d' => (int) ($count->runs ?? 0),
                'failed7d' => (int) ($count->failed ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * The schedule's events. In a web request routes/console.php is not loaded yet; bootstrapping the console kernel
     * loads it once (it is guarded against loading twice).
     *
     * @return list<Event>
     */
    public static function events(): array
    {
        try {
            app(ConsoleKernel::class)->bootstrap();

            return array_values(app(Schedule::class)->events());
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @return array<string, ScheduledTaskRun>
     */
    private static function latestRuns(): array
    {
        $newest = DB::table('scheduled_task_runs')->groupBy('task', 'expression')
            ->selectRaw('task as latest_task, expression as latest_expression, max(started_at) as latest_started_at');

        $runs = ScheduledTaskRun::query()
            ->joinSub($newest, 'latest', fn ($join) => $join->on('latest.latest_task', '=', 'scheduled_task_runs.task')
                ->on('latest.latest_expression', '=', 'scheduled_task_runs.expression')
                ->on('latest.latest_started_at', '=', 'scheduled_task_runs.started_at'))
            ->select('scheduled_task_runs.*')
            ->orderBy('scheduled_task_runs.id')
            ->get();

        $latest = [];

        foreach ($runs as $run) {
            $latest[$run->task."\0".$run->expression] = $run;
        }

        return $latest;
    }

    /**
     * @return LastRun
     */
    private static function lastRun(ScheduledTaskRun $run, CarbonImmutable $now): array
    {
        $state = match (true) {
            $run->succeeded === true => 'succeeded',
            $run->succeeded === false => 'failed',
            $run->started_at->lessThan($now->subHours(self::UNFINISHED_HOURS)) => 'unfinished',
            default => 'running',
        };

        return [
            'startedAt' => $run->started_at->utc()->toIso8601ZuluString(),
            'finishedAt' => $run->finished_at?->utc()->toIso8601ZuluString(),
            'durationMs' => $run->duration_ms,
            'state' => $state,
            'error' => $run->error === null ? null : SecretText::clean($run->error),
        ];
    }

    private static function isLate(ScheduledTaskRun $run, string $expression, string $timezone, CarbonImmutable $now): bool
    {
        try {
            $due = CarbonImmutable::instance((new CronExpression($expression))->getPreviousRunDate($now->setTimezone($timezone), 0, true));
        } catch (Throwable) {
            return false;
        }

        return $due->lessThan($now->subMinutes(self::LATE_GRACE_MINUTES))
            && $run->started_at->lessThan($due->subMinutes(self::LATE_GRACE_MINUTES));
    }

    private static function nextDue(Event $event): ?string
    {
        try {
            return CarbonImmutable::instance($event->nextRunDate())->utc()->toIso8601ZuluString();
        } catch (Throwable) {
            return null;
        }
    }

    private static function timezone(Event $event): string
    {
        $zone = $event->timezone ?: config('app.timezone', 'UTC');

        return $zone instanceof DateTimeZone ? $zone->getName() : (string) $zone;
    }
}
