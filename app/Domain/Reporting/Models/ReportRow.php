<?php

namespace App\Domain\Reporting\Models;

use App\Domain\Reporting\Actions\RebuildReportDays;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Base of the `rpt_*` models (module 3.1): tenant-scoped (BelongsToCompany), read only. Rows are written only by
 * {@see RebuildReportDays} (query builder, whole shop-days); the tables have composite primary keys, so read them
 * with queries, never find().
 *
 * @property string $company_id
 * @property string $branch_id
 * @property string $trading_day
 * @property string $register_id
 */
abstract class ReportRow extends Model
{
    use BelongsToCompany;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Reporting rows are rebuilt from the raw till rows, never saved.'));
        static::deleting(fn () => throw new LogicException('Reporting rows are rebuilt from the raw till rows, never deleted.'));
    }
}
