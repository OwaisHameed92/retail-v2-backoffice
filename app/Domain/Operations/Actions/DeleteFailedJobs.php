<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Deletes failed jobs from the failed list (`php artisan queue:forget {uuid}`): they are not run again. Each deleted
 * job is audited with its name, queue and failure time.
 */
final class DeleteFailedJobs
{
    public function __construct(
        private readonly FailedJobProviderInterface $failer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return array{deleted: int, missing: int}
     */
    public function handle(array $uuids): array
    {
        $result = ['deleted' => 0, 'missing' => 0];

        foreach (array_values(array_unique($uuids)) as $uuid) {
            $job = $this->failer->find($uuid);

            if ($job === null || ! $this->failer->forget($uuid)) {
                $result['missing']++;

                continue;
            }

            $result['deleted']++;
            $this->audit->handle('failed_job.deleted', meta: RetryFailedJobs::meta($uuid, $job));
        }

        return $result;
    }
}
