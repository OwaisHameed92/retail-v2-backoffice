<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Models\FailedJob;
use App\Domain\Operations\Support\FailedJobBusiness;
use App\Domain\Operations\Support\FailedJobPayload;
use App\Domain\Operations\Support\SecretText;
use App\Domain\Shared\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Throwable;

/**
 * The failed jobs list (failed_jobs, newest first): job, queue, when, the exception's first line and the business
 * when the payload names one. Text goes through SecretText before it leaves the server.
 *
 * @phpstan-type FailedJobRow array{uuid: string, job: string, jobClass: string, queue: string, connection: string, failedAt: string|null, error: string, attempts: int|null, business: array{id: string, name: string}|null}
 */
final class FailedJobList
{
    /**
     * @return array{data: list<mixed>, meta: array<string, mixed>}
     */
    public static function paginate(Request $request, ?string $queue = null): array
    {
        $table = TableQuery::from($request)
            ->searchable(['payload', 'exception', 'queue', 'uuid'])
            ->sortable(['failed_at', 'queue'])
            ->defaultSort('failed_at', 'desc');

        $query = FailedJob::query()
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->when($queue !== null, fn (Builder $q) => $q->where('queue', $queue));

        try {
            $page = $table->paginate($query, fn (FailedJob $job) => $job);
        } catch (Throwable $e) {
            report($e);

            return ['data' => [], 'meta' => ['page' => 1, 'perPage' => $table->perPage(), 'total' => 0, 'lastPage' => 1, 'search' => $table->search(), 'sort' => $table->sort(), 'direction' => $table->direction()]];
        }

        /** @var list<FailedJob> $jobs */
        $jobs = $page['data'];
        $payloads = [];

        foreach ($jobs as $job) {
            $payloads[$job->uuid] = new FailedJobPayload($job->payload);
        }

        $businesses = FailedJobBusiness::resolve($payloads);
        $page['data'] = array_map(fn (FailedJob $job) => self::row($job, $payloads[$job->uuid], $businesses[$job->uuid] ?? null), $jobs);

        return $page;
    }

    /**
     * Queues that have failed jobs, for the filter.
     *
     * @return list<string>
     */
    public static function queues(): array
    {
        try {
            return FailedJob::query()->distinct()->orderBy('queue')->pluck('queue')->map(fn ($q) => (string) $q)->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array{id: string, name: string}|null  $business
     * @return FailedJobRow
     */
    public static function row(FailedJob $job, FailedJobPayload $payload, ?array $business): array
    {
        return [
            'uuid' => $job->uuid,
            'job' => $payload->shortName(),
            'jobClass' => $payload->displayName(),
            'queue' => (string) $job->queue,
            'connection' => (string) $job->connection,
            'failedAt' => $job->failed_at?->utc()->toIso8601ZuluString(),
            'error' => SecretText::firstLine($job->exception, 240),
            'attempts' => $payload->attempts(),
            'business' => $business,
        ];
    }
}
