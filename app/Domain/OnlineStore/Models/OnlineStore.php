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
 * business). Written by SaveOnlineStore, SetStoreLive and SetTakingOrders; created, and its add-on, template and price
 * set, by the admin's SetOnlineStoreAddOn (OS-6).
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
 * @property string|null $featured_mode OS-8: FeaturedMode value; null = best sellers
 * @property list<string>|null $featured_product_ids OS-8: the hand-picked till product ids, in order
 * @property list<string>|null $home_departments OS-8: the department ids with their own home row, in order
 * @property string|null $about_text OS-8: the business's own "About us" text (else the template's)
 * @property bool $is_live
 * @property bool $taking_orders
 * @property string|null $order_email
 * @property string|null $monthly_price the add-on's price for this business; null = the default
 * @property bool $addon_on OS-6: the admin switched the online store add-on on (the store is usable)
 * @property CarbonImmutable|null $addon_on_at when the add-on was last switched on
 * @property CarbonImmutable|null $live_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, OnlineStoreShop> $shops
 * @property-read Collection<int, OnlineStoreBanner> $banners
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
     * @return HasMany<OnlineStoreBanner, $this>
     */
    public function banners(): HasMany
    {
        return $this->hasMany(OnlineStoreBanner::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => StoreTemplateCast::class,
            'sections' => 'array',
            'featured_product_ids' => 'array',
            'home_departments' => 'array',
            'is_live' => 'boolean',
            'taking_orders' => 'boolean',
            'monthly_price' => MoneyCast::class,
            'addon_on' => 'boolean',
            'addon_on_at' => 'immutable_datetime',
            'live_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
