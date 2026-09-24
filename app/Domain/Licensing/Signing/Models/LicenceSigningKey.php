<?php

namespace App\Domain\Licensing\Signing\Models;

use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\SigningKey;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Storage row for a licence signing key. Use `KeyStore` instead of querying this model directly.
 * `secret_key` is encrypted at rest with APP_KEY and hidden from toArray()/toJson().
 *
 * @property string $id
 * @property string $kid
 * @property string $public_key
 * @property string|null $secret_key
 * @property bool $is_active
 * @property CarbonInterface|null $retired_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LicenceSigningKey extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kid',
        'public_key',
        'secret_key',
        'is_active',
        'retired_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'secret_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'is_active' => 'boolean',
            'retired_at' => 'datetime',
        ];
    }

    public function toSigningKey(): SigningKey
    {
        $secret = $this->is_active && $this->secret_key !== null ? Base64Url::decode($this->secret_key) : null;

        return new SigningKey(
            kid: $this->kid,
            publicKey: Base64Url::decode($this->public_key),
            secretKey: $secret,
            createdAt: CarbonImmutable::parse($this->created_at ?? 'now')->utc(),
            retiredAt: $this->retired_at !== null ? CarbonImmutable::parse($this->retired_at)->utc() : null,
        );
    }
}
