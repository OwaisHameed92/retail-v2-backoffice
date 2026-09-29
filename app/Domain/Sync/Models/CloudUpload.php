<?php

namespace App\Domain\Sync\Models;

use App\Domain\Sync\Enums\CloudUploadStatus;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop's move to the cloud (module 2.8, contract v1.4.1 §17.8): opened by `cloud/migrate`, filled by `sync/push`
 * in initial mode (its rows keyed by (uploadId, seq) in sync_applied_changes), closed by `cloud/migrate/complete`
 * once every row the till counted has arrived. `id` is the `uploadId`. One per (branch, install): a retried migrate
 * gets the same upload. Tenant-owned; the API reads it with `withoutCompanyScope()` and always filters by branch.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string|null $sync_key_id
 * @property string|null $licence_id Our licence the till was given.
 * @property string $install_id
 * @property string|null $install_code
 * @property string|null $device_name
 * @property string|null $app_version
 * @property string $till_company_id
 * @property string $till_branch_id
 * @property string|null $till_register_id The main till's own register id.
 * @property CloudUploadStatus $status
 * @property int $expected_rows
 * @property array<string, int>|null $expected_row_counts
 * @property int $snapshot_change_log_seq
 * @property CarbonImmutable|null $first_sale_at
 * @property CarbonImmutable|null $last_sale_at
 * @property string|null $local_licence_id The local (generator) licence the shop moved from.
 * @property int $carried_over_days
 * @property array<string, array{localId: string, portalId: string, action: string}>|null $id_mapping
 * @property int $acknowledged_seq Highest upload seq held with every seq below it.
 * @property int $received_rows
 * @property CarbonImmutable|null $last_batch_at
 * @property int $complete_calls
 * @property list<array{entity: string, expected: int, received: int}>|null $missing Last `incomplete` answer.
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch|null $branch
 */
class CloudUpload extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> The table's defaults, so a new upload reads the same before and after a refresh. */
    protected $attributes = [
        'status' => 'open', 'expected_rows' => 0, 'snapshot_change_log_seq' => 0, 'carried_over_days' => 0,
        'acknowledged_seq' => 0, 'received_rows' => 0, 'complete_calls' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CloudUploadStatus::class,
            'expected_rows' => 'integer',
            'expected_row_counts' => 'array',
            'snapshot_change_log_seq' => 'integer',
            'first_sale_at' => 'immutable_datetime',
            'last_sale_at' => 'immutable_datetime',
            'carried_over_days' => 'integer',
            'id_mapping' => 'array',
            'acknowledged_seq' => 'integer',
            'received_rows' => 'integer',
            'last_batch_at' => 'immutable_datetime',
            'complete_calls' => 'integer',
            'missing' => 'array',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isOpen(): bool
    {
        return $this->status === CloudUploadStatus::Open;
    }
}
