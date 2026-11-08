<?php

namespace App\Domain\Operations\Models;

use App\Domain\Operations\Support\ScheduledTaskRecorder;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of a scheduled task, recorded by {@see ScheduledTaskRecorder} (admin "Sync & jobs"). Platform data, not
 * tenant-owned. `succeeded` is null while the task runs. Kept {@see self::KEEP_DAYS} days.
 *
 * @property string $id
 * @property string $task Command without the PHP binary ("billing:run") or the callback's name.
 * @property string $expression Cron expression the task was scheduled with.
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 * @property bool|null $succeeded
 * @property int|null $exit_code
 * @property string|null $error First line of the failure, secrets removed.
 */
class ScheduledTaskRun extends Model
{
    use HasUlids, MassPrunable;

    public const KEEP_DAYS = 30;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => UtcDateTimeCast::class,
            'finished_at' => UtcDateTimeCast::class,
            'duration_ms' => 'integer',
            'succeeded' => 'boolean',
            'exit_code' => 'integer',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('started_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
