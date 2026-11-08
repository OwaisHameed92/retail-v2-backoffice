<?php

namespace App\Domain\Devices\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A read-only row of the admin Devices list: tills and handhelds unioned into the `devices` subquery
 * (DeviceList), wrapped in a model only so the shared TableQuery can search, sort and page it. There is no
 * `devices` table and nothing is ever written through this class.
 *
 * @property string $kind
 * @property string $id Licence id (till) or handheld token id.
 * @property string $company_id
 * @property string $company_name
 * @property string $branch_id
 * @property string $branch_name
 * @property string|null $branch_code
 * @property string $name Till name or handheld user name.
 * @property string|null $code Till number.
 * @property bool|null $is_main_till
 * @property string|null $app_version
 * @property CarbonImmutable|null $last_seen_at
 * @property string|null $install_id
 * @property string|null $device_name
 * @property string|null $key_last4
 * @property int|null $clock_skew_seconds
 * @property string|null $status Handhelds only; a till's status comes from its licence.
 */
class DeviceRow extends Model
{
    protected $table = 'devices';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main_till' => 'boolean',
            'last_seen_at' => 'immutable_datetime',
            'clock_skew_seconds' => 'integer',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        throw new LogicException('DeviceRow is a read-only list row.');
    }
}
