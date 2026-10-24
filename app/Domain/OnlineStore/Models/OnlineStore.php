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
 * @property string|null $dna_client_id OS-9: DNA Payments client id (encrypted at rest)
 * @property string|null $dna_client_secret OS-9: DNA Payments client secret (encrypted at rest, never shown or logged)
 * @property string|null $dna_terminal_id OS-9: DNA Payments terminal id (encrypted at rest)
 * @property bool $dna_test_mode OS-9: DNA's test environment (no real money)
 * @property list<string>|null $collection_payments OS-9: ways to pay for a collection; null = pay at the shop
 * @property list<string>|null $delivery_payments OS-9: ways to pay for a delivery; null = pay on delivery
 * @property string|null $trading_name OS-11 (audit OSA-2): the trader details (StoreTrader, SaveStoreLegal)
 * @property string|null $legal_name
 * @property string|null $legal_address
 * @property string|null $company_number
 * @property string|null $vat_number
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property array<string, string>|null $legal_extra the shop's own text per legal page (LegalPage value => text)
 * @property string|null $aisle_source OS-11: AisleSource value; null = automatic (StoreAisles)
 * @property list<array<string, mixed>>|null $aisles OS-11: the shop's aisle choices, in order
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

    /** @var list<string> OS-9: the DNA credentials never leave the server (PaymentSettingsPage shows the last 4). */
    protected $hidden = ['dna_client_id', 'dna_client_secret', 'dna_terminal_id'];

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
            'dna_client_id' => 'encrypted',
            'dna_client_secret' => 'encrypted',
            'dna_terminal_id' => 'encrypted',
            'dna_test_mode' => 'boolean',
            'collection_payments' => 'array',
            'delivery_payments' => 'array',
            'legal_extra' => 'array',
            'aisles' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
