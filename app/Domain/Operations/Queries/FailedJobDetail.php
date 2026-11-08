<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Models\FailedJob;
use App\Domain\Operations\Support\FailedJobBusiness;
use App\Domain\Operations\Support\FailedJobPayload;
use App\Domain\Operations\Support\SecretText;

/**
 * One failed job for the detail dialog: the list row plus the full exception (secrets removed, cut to
 * {@see self::MAX_EXCEPTION} characters), tries and timeout, and the records it was queued with. The raw payload is
 * never sent.
 */
final class FailedJobDetail
{
    public const MAX_EXCEPTION = 20000;

    /**
     * @return array<string, mixed>|null
     */
    public static function for(string $uuid): ?array
    {
        $job = FailedJob::query()->where('uuid', $uuid)->first();

        if ($job === null) {
            return null;
        }

        $payload = new FailedJobPayload($job->payload);
        $business = FailedJobBusiness::resolve(['job' => $payload])['job'] ?? null;
        $exception = (string) $job->exception;

        return FailedJobList::row($job, $payload, $business) + [
            'maxTries' => $payload->maxTries(),
            'timeout' => $payload->timeout(),
            'records' => array_map(fn (array $m) => ['type' => class_basename($m['class']), 'id' => $m['id']], $payload->models()),
            'exception' => SecretText::clean(mb_substr($exception, 0, self::MAX_EXCEPTION)),
            'exceptionTruncated' => mb_strlen($exception) > self::MAX_EXCEPTION,
        ];
    }
}
