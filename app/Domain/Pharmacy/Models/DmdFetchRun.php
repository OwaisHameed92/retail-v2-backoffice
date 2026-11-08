<?php

namespace App\Domain\Pharmacy\Models;

use App\Domain\Pharmacy\Enums\DmdFetchOutcome;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of the weekly dm+d fetch (W4): its outcome and, when it failed, a redacted error (never the TRUD key).
 *
 * @property string $id
 * @property DmdFetchOutcome $outcome
 * @property string|null $release_id
 * @property string|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
class DmdFetchRun extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => DmdFetchOutcome::class,
            'started_at' => UtcDateTimeCast::class,
            'finished_at' => UtcDateTimeCast::class,
        ];
    }
}
