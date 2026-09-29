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
 * One shop's health at the last refresh (module 2.7). Written only by {@see RefreshTillHealth}.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property TillState $state
 * @property SyncState $sync_state
 * @property CarbonImmutable|null $last_contact_at
 * @property CarbonImmutable|null $last_sync_at
 * @property CarbonImmutable|null $last_push_at
 * @property CarbonImmutable|null $last_pull_at
 * @property CarbonImmutable|null $last_error_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property int $tills
 * @property int $tills_online
 * @property int $tills_offline
 * @property CarbonImmutable $checked_at
 */
class BranchHealth extends Model
{
    use BelongsToCompany, HasPortalUlid;

    protected $table = 'branch_health';

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
            'tills' => 'integer',
            'tills_online' => 'integer',
            'tills_offline' => 'integer',
            'last_contact_at' => 'immutable_datetime',
            'last_sync_at' => 'immutable_datetime',
            'last_push_at' => 'immutable_datetime',
            'last_pull_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }
}
