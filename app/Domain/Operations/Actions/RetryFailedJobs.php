<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Operations\Support\FailedJobPayload;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Sends failed jobs back to their queue, exactly like `php artisan queue:retry {uuid}` (which also removes them from
 * the failed list), one at a time so one broken payload does not stop the rest. Each retried job is audited.
 */
final class RetryFailedJobs
{
    public function __construct(
        private readonly FailedJobProviderInterface $failer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return array{retried: int, failed: int, missing: int}
     */
    public function handle(array $uuids): array
    {
        $result = ['retried' => 0, 'failed' => 0, 'missing' => 0];

        foreach (array_values(array_unique($uuids)) as $uuid) {
            $job = $this->failer->find($uuid);

            if ($job === null) {
                $result['missing']++;

                continue;
            }

            try {
                Artisan::call('queue:retry', ['id' => [$uuid]]);
            } catch (Throwable $e) {
                report($e);
            }

            if ($this->failer->find($uuid) !== null) {
                $result['failed']++;

                continue;
            }

            $result['retried']++;
            $this->audit->handle('failed_job.retried', meta: self::meta($uuid, $job));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(string $uuid, object $job): array
    {
        return [
            'uuid' => $uuid,
            'job' => (new FailedJobPayload(is_string($job->payload ?? null) ? $job->payload : null))->displayName(),
            'queue' => is_string($job->queue ?? null) ? $job->queue : null,
            'connection' => is_string($job->connection ?? null) ? $job->connection : null,
            'failedAt' => is_string($job->failed_at ?? null) ? $job->failed_at : null,
        ];
    }
}
