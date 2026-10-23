<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * How one till product shows online (SetOnlineProduct): hidden or not, a photo and a short description. No row =
 * shown, with the till's own description. The till's product row itself is never changed.
 *
 * @property string $id
 * @property string $company_id
 * @property string $product_id the till's product id
 * @property bool $hidden
 * @property string|null $photo_path
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OnlineStoreProduct extends Model
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
            'hidden' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
