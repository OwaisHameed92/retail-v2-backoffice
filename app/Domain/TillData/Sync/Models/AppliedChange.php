<?php

namespace App\Domain\TillData\Sync\Models;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One accepted pushed change in the sync_applied_changes ledger (dedupe on company + sending branch + seq).
 * Written only by ChangeLedger with the query builder; this model is for reading (monitoring, support).
 *
 * @property int $id
 * @property string $company_id
 * @property string $branch_id
 * @property int $seq
 * @property string $entity
 * @property string $entity_id
 * @property int $version
 * @property string $op
 * @property ChangeOutcome $outcome
 * @property CarbonImmutable $applied_at
 */
class AppliedChange extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sync_applied_changes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'version' => 'integer',
            'outcome' => ChangeOutcome::class,
            'applied_at' => UtcDateTimeCast::class,
        ];
    }
}
