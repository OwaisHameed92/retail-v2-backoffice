<?php

namespace App\Domain\Salon\Models;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Where an online request came from (W3a, README §9 `?src=`) and the booking form's own key (a double tap is one
 * request). One per SalonOnlineBooking the portal made; the id is the request's. Portal-only, never sent to the till.
 *
 * @property string $id the SalonOnlineBooking id
 * @property string $company_id
 * @property string $branch_id
 * @property string $client_key
 * @property string $src
 * @property CarbonImmutable|null $created_at
 */
class SalonOnlineRequestSource extends Model
{
    use BelongsToCompany;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTimeCast::class];
    }
}
