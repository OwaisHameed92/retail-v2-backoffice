<?php

namespace App\Domain\TillHealth\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\TillHealth\Actions\RefreshTillHealth;
use App\Domain\TillHealth\Enums\SyncState;
use App\Domain\TillHealth\Enums\TillState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One till's health at the last refresh (module 2.7). Written only by {@see RefreshTillHealth}; read by the admin
 * Till health list and dashboard (across companies: `withoutCompanyScope()`).
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $register_id
 * @property string|null $licence_id
 * @property TillState $state
 * @property bool $is_sync_till
 * @property SyncState $sync_state
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $last_validated_at
 * @property CarbonImmutable|null $last_push_at
 * @property CarbonImmutable|null $last_pull_at
 * @property string|null $app_version
 * @property bool $app_outdated
 * @property string|null $contract_version
 * @property string|null $install_id
 * @property string|null $device_name
 * @property int|null $clock_skew_seconds
 * @property bool $clock_skewed
 * @property int|null $pending_sync_rows
 * @property int|null $pending_sync_rows_previous
 * @property CarbonImmutable|null $diagnostics_at
 * @property bool|null $lock_locked
 * @property string|null $lock_reason
 * @property int $problem_count
 * @property CarbonImmutable $checked_at
 */
class TillHealth extends Model
{
    use BelongsToCompany, HasPortalUlid;

    protected $table = 'till_health';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => TillState::class,
            'sync_state' => SyncState::class,
            'is_sync_till' => 'boolean',
            'app_outdated' => 'boolean',
            'clock_skewed' => 'boolean',
            'lock_locked' => 'boolean',
            'clock_skew_seconds' => 'integer',
            'pending_sync_rows' => 'integer',
            'pending_sync_rows_previous' => 'integer',
            'problem_count' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'last_validated_at' => 'immutable_datetime',
            'last_push_at' => 'immutable_datetime',
            'last_pull_at' => 'immutable_datetime',
            'diagnostics_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }
}
