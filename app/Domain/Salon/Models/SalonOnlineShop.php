<?php

namespace App\Domain\Salon\Models;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One salon shop's online booking state (W3a, salon pack 2026-11-08 README §4.1, §4.2, §4.5): its public address, the
 * newest booking page its main till published, the manage-link key, the last heartbeat and the last phone-queue push.
 * Written only by the `sync/salon/*` actions; read by the public pages. Portal-only, never a till row.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $slug the public address `/book/{slug}`: short, stable, never a sequence
 * @property array<string, mixed>|null $page the page as the till sent it, without `manageLink.key`
 * @property string|null $page_hash sha256 of the whole body as decoded (key included)
 * @property CarbonImmutable|null $page_generated_at
 * @property CarbonImmutable|null $page_stored_at
 * @property string|null $manage_key_id
 * @property string|null $manage_key encrypted at rest; never logged, never shown
 * @property CarbonImmutable|null $last_heard_at our clock
 * @property CarbonImmutable|null $heartbeat_at the till's `at`
 * @property bool|null $accepting
 * @property bool|null $queue_join_open
 * @property int|null $pending_requests
 * @property int|null $offline_after_min
 * @property array<string, mixed>|null $queue the newest `PUT sync/salon/queue` body
 * @property CarbonImmutable|null $queue_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SalonOnlineShop extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['manage_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page' => 'array',
            'page_generated_at' => UtcDateTimeCast::class,
            'page_stored_at' => UtcDateTimeCast::class,
            'manage_key' => 'encrypted',
            'last_heard_at' => UtcDateTimeCast::class,
            'heartbeat_at' => UtcDateTimeCast::class,
            'accepting' => 'boolean',
            'queue_join_open' => 'boolean',
            'pending_requests' => 'integer',
            'offline_after_min' => 'integer',
            'queue' => 'array',
            'queue_at' => UtcDateTimeCast::class,
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
}
