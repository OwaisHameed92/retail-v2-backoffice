<?php

namespace App\Domain\Operations\Queries;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;

/**
 * Props of the admin "Sync & jobs" page. Each section is a closure so a partial reload (the failed jobs table's
 * paging and search) computes only what it asks for.
 */
final class SyncJobsPage
{
    /**
     * @return array<string, Closure>
     */
    public static function props(Request $request, CarbonImmutable $now): array
    {
        $queue = is_string($request->query('queue')) && $request->query('queue') !== '' ? mb_substr((string) $request->query('queue'), 0, 191) : null;
        $sync = null;
        $syncProblems = function () use (&$sync, $now): array {
            return $sync ??= SyncProblemList::compute($now);
        };

        return [
            'checkedAt' => fn () => $now->utc()->toIso8601ZuluString(),
            'health' => fn () => OperationsHealth::compute($now, $syncProblems()['last24h']),
            'tasks' => fn () => ScheduledTaskList::compute($now),
            'failedJobs' => fn () => FailedJobList::paginate($request, $queue),
            'failedFilters' => fn () => ['queue' => $queue, 'queues' => FailedJobList::queues()],
            'syncProblems' => fn () => ['rows' => $syncProblems()['rows'], 'total' => $syncProblems()['total'], 'windowDays' => SyncProblemList::WINDOW_DAYS],
            'traffic' => fn () => TillTrafficList::compute($now) + ['quietHours' => TillTrafficList::QUIET_HOURS],
        ];
    }
}
