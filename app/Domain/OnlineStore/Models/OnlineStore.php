<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\OnlineStore\Casts\StoreTemplateCast;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business's online store (docs/online-store.md): one per business, found by its `slug` (unique across every
 * business). Written by SaveOnlineStore, SetStoreLive and SetTakingOrders.
 *
 * @property string $id
 * @property string $company_id
 * @property string $slug
 * @property StoreTemplate $template set by Switch & Save (admin), never by the business; unknown values read as Fresh Market
 * @property string|null $logo_path
 * @property string|null $banner_path
 * @property string|null $brand_colour "#RRGGBB"
 * @property string|null $headline
 * @property list<array{key: string, enabled: bool}>|null $sections home page sections in order
 * @property bool $is_live
 * @property bool $taking_orders
 * @property string|null $order_email
 * @property string|null $monthly_price the add-on's price for this business; null = the default
 * @property CarbonImmutable|null $live_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, OnlineStoreShop> $shops
 */
class OnlineStore extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return HasMany<OnlineStoreShop, $this>
     */
    public function shops(): HasMany
    {
        return $this->hasMany(OnlineStoreShop::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => StoreTemplateCast::class,
            'sections' => 'array',
            'is_live' => 'boolean',
            'taking_orders' => 'boolean',
            'monthly_price' => MoneyCast::class,
            'live_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
