<?php

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Actions\ProcessDirtyReportDays;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Rebuilds one business's dirty shop-days (module 3.1), dispatched after a push that applied sale rows. One job per
 * business waits in the queue at a time (pushes every few seconds collapse into it) and two never run at once for
 * the same business. A lost or failed job is picked up by the minutely `reports:process-dirty` sweep.
 */
class ProcessDirtyReportDaysJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Real failures; waiting for another run of the same business (released) does not count. */
    public int $maxExceptions = 3;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $companyId)
    {
        $this->afterCommit = true;
        $queue = config('reporting.queue');
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : null);
    }

    public function uniqueId(): string
    {
        return $this->companyId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('reports:'.$this->companyId))->releaseAfter(10)->expireAfter(900)];
    }

    /** Keep retrying (a long initial upload can hold the business for minutes); the sweep covers anything later. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(ProcessDirtyReportDays $process): void
    {
        $process->handle($this->companyId);
    }
}
