<?php

namespace App\Domain\Licensing\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The hash of a key replaced by "Reissue key" (written by ReissueKey), with a hash of the PC it was bound to.
 * The licence API still answers `licence.not_found` for it, but raises a `reissuedKeyUsed` alert when that
 * same PC keeps using it.
 *
 * @property string $id
 * @property string $company_id
 * @property string $licence_id
 * @property string $key_hash
 * @property string $key_last4
 * @property string|null $bound_device_hash
 * @property CarbonImmutable $retired_at
 */
class RetiredLicenceKey extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'licence_id',
        'key_hash',
        'key_last4',
        'bound_device_hash',
        'retired_at',
    ];

    /** @var list<string> */
    protected $hidden = ['key_hash', 'bound_device_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retired_at' => 'immutable_datetime',
        ];
    }
}
