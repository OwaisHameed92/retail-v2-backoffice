<?php

namespace App\Domain\TillData\Sync\Models;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\TillData\Sync\Enums\ConflictKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pushed change the store did not (fully) apply and a person or module 2.5 should decide on. Written by the
 * sync applier only; tenant-scoped for the portal's sync status screen (module 2.7).
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string $entity
 * @property string $entity_id
 * @property ConflictKind $kind
 * @property int|null $local_version
 * @property int $incoming_version
 * @property int|null $incoming_seq
 * @property CarbonImmutable|null $incoming_at
 * @property string|null $incoming_payload JSON as the till sent it (secret members removed)
 * @property string|null $detail
 * @property string $status open|resolved
 * @property string|null $resolution
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SyncConflict extends Model
{
    use BelongsToCompany, HasPortalUlid;

    protected $table = 'sync_conflicts';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ConflictKind::class,
            'local_version' => 'integer',
            'incoming_version' => 'integer',
            'incoming_seq' => 'integer',
            'incoming_at' => UtcDateTimeCast::class,
            'resolved_at' => UtcDateTimeCast::class,
        ];
    }

    /**
     * @param  Builder<SyncConflict>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function incomingPayload(): ?array
    {
        return $this->incoming_payload === null ? null : json_decode($this->incoming_payload, true);
    }
}
