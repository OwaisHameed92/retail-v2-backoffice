<?php

namespace App\Domain\Operations\Models;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model of Laravel's `failed_jobs` table (database-uuids failer) for the admin "Sync & jobs" list. Never
 * written through this model: retry and delete go through the queue failer (Operations\Actions).
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property CarbonImmutable|null $failed_at
 */
class FailedJob extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    public function getTable(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['failed_at' => UtcDateTimeCast::class];
    }
}
