<?php

namespace App\Domain\Sync\Models;

use App\Domain\Sync\Support\SyncStatusRecorder;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch's sync health (modules 2.2 and 2.5, read by 2.7 Till health). Written only by {@see SyncStatusRecorder}.
 * Counts are for `rows_day` (the Europe/London day of the last push); an older day means nothing arrived today.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property CarbonImmutable|null $last_hello_at
 * @property CarbonImmutable|null $last_push_at
 * @property CarbonImmutable|null $last_pull_at
 * @property int|null $last_pull_since The `since` of the last pull.
 * @property int|null $last_pull_version The highest version the last pull sent (its `highestVersion`).
 * @property int|null $last_pull_rows
 * @property int|null $last_acknowledged_seq Delta push (the branch's ChangeLog).
 * @property string|null $last_upload_id Initial upload (§17.8), with its own seq.
 * @property int|null $last_upload_seq
 * @property CarbonImmutable|null $rows_day
 * @property int $rows_accepted_today
 * @property int $rows_rejected_today
 * @property CarbonImmutable|null $last_error_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property string|null $last_rejected_key
 * @property string|null $last_app_version
 * @property string|null $last_register_id Our register, when the sending till is mapped.
 * @property string|null $last_till_register_id X-SSPOS-Register-Id as the till sent it.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch|null $branch
 */
class SyncBranchStatus extends Model
{
    use BelongsToCompany, HasPortalUlid;

    protected $table = 'sync_branch_status';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_hello_at' => 'immutable_datetime',
            'last_push_at' => 'immutable_datetime',
            'last_pull_at' => 'immutable_datetime',
            'last_pull_since' => 'integer',
            'last_pull_version' => 'integer',
            'last_pull_rows' => 'integer',
            'last_acknowledged_seq' => 'integer',
            'last_upload_seq' => 'integer',
            'rows_day' => 'immutable_date',
            'rows_accepted_today' => 'integer',
            'rows_rejected_today' => 'integer',
            'last_error_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
