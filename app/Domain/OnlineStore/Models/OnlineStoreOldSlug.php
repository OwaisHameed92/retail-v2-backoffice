<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A store's previous address (OS-11, audit OSA-6, StoreOldSlugs): until `expires_at` the public store redirects it
 * (301) to the store's current address, and no other business can take it. The slug is unique across every business.
 *
 * @property string $id
 * @property string $company_id
 * @property string $online_store_id
 * @property string $slug
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OnlineStoreOldSlug extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
