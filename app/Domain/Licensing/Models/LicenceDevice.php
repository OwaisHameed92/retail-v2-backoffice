<?php

namespace App\Domain\Licensing\Models;

use App\Domain\Licensing\Api\Support\DeviceHash;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A PC that has used a licence key (module 1.5): the device id is kept only as an HMAC hash
 * ({@see DeviceHash}). Written by the licence API only.
 *
 * @property string $id
 * @property string $company_id
 * @property string $licence_id
 * @property string $device_hash
 * @property string|null $device_name
 * @property string|null $last_ip
 * @property string|null $last_app_version
 * @property string $last_outcome
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property int $times_seen
 */
class LicenceDevice extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'licence_id',
        'device_hash',
        'device_name',
        'last_ip',
        'last_app_version',
        'last_outcome',
        'first_seen_at',
        'last_seen_at',
        'times_seen',
    ];

    /** @var list<string> */
    protected $hidden = ['device_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'times_seen' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
