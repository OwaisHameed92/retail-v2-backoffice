<?php

namespace App\Domain\Licensing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One 409 `key.used_on_another_install` sent (contract v1.4.1 §17.10 "for support"): a second PC reported a local
 * key already bound to another install code. Written only by LocalKeyRegister.
 *
 * @property int $id
 * @property string $local_licence_key_id
 * @property string $install_code
 * @property string|null $install_id
 * @property string|null $device_name
 * @property string $token_sha256
 * @property CarbonImmutable $refused_at
 */
class LocalLicenceKeyRefusal extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['refused_at' => 'immutable_datetime'];
    }
}
