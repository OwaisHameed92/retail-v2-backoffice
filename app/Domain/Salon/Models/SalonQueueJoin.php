<?php

namespace App\Domain\Salon\Models;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A phone join from the queue-join page (W3a, salon pack 2026-11-08 README §4.3, §4.4). The id is the `joinId` (our
 * ULID) the till uses as its ticket's id, so a join it lists twice is one ticket. Listed to the shop's main till until
 * answered, or dropped after `salon.join_ttl_minutes` with nobody answering. Portal-only, never a till row.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $client_key the form's own key: a double tap is one join
 * @property string $first_name
 * @property string $phone
 * @property list<string> $service_ids
 * @property string|null $stylist_user_id
 * @property string $note
 * @property string $src
 * @property CarbonImmutable $joined_at
 * @property CarbonImmutable|null $answered_at
 * @property string|null $answer_status joined | refused
 * @property array<string, mixed>|null $answer the till's answer body, whole
 * @property CarbonImmutable|null $dropped_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SalonQueueJoin extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_ids' => 'array',
            'joined_at' => UtcDateTimeCast::class,
            'answered_at' => UtcDateTimeCast::class,
            'answer' => 'array',
            'dropped_at' => UtcDateTimeCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
