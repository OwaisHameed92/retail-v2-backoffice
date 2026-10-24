<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Actions\SaveOnlineStore;
use App\Domain\OnlineStore\Actions\SaveStoreAisles;
use App\Domain\OnlineStore\Enums\BannerLink;
use App\Domain\OnlineStore\Enums\FeaturedMode;
use App\Domain\OnlineStore\Enums\StoreSection;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Legal\StoreTrader;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreBanner;
use App\Domain\OnlineStore\Support\OnlineCatalogueQuery;
use App\Domain\OnlineStore\Support\StoreAddress;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;

/**
 * The portal's store builder page (OS-3, `app/online-store/builder`): the business's store settings as React edits
 * them, its template (read only: Switch & Save sets it when the add-on is switched on) with its default banner
 * photo, the preset brand colours, the address prefix for the live preview and the "ready to go live" checklist
 * (collection shops, products shown at the first one). OS-8: the home banners, the featured products setting (with
 * the hand-picked products' names) and the departments for the department rows. No store yet → `store` and
 * `template` are null and the page
 * says the online store is not switched on.
 */
final class StoreBuilderPage
{
    /** Preset main colours (name => hex); any other #RRGGBB can be typed. */
    public const COLOURS = ['Forest' => '#0B4A34', 'Red' => '#B42318', 'Blue' => '#1F4E9C', 'Purple' => '#5B2A86', 'Charcoal' => '#2A2522'];

    /** The template's "About us" text, shown as the placeholder (the public page uses it when the field is empty). */
    private const ABOUT_PLACEHOLDER = [
        'freshMarket' => 'Your local shop, now online. What you see here comes straight from our tills, so the prices and stock are what you would find in the shop today.',
        'butcher' => 'Your local butcher, now online. Prices and stock come straight from our tills. Weighed cuts show an estimated price: we weigh your order at the counter and you pay the real weight.',
    ];

    /** What each home section shows. */
    private const SECTION_HINTS = [
        'hero' => 'Your banners at the top, one after another.',
        'departments' => 'Photo tiles for each department with products online.',
        'featured' => 'Best sellers, hand-picked or newest products (set below).',
        'howItWorks' => 'Order online, pick a time, pay at the shop.',
        'shops' => 'Your collection shops with their opening hours.',
        'about' => 'A short note about your business.',
        'offers' => 'Products with a till offer on today, with the offer.',
        'departmentRows' => 'A row of products for each department you choose (set below).',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, bool $canEdit): array
    {
        $store = OnlineStore::query()->where('company_id', $company->id)->first();
        $shops = $store === null ? collect() : app(StorefrontShops::class)->handle($store->setRelation('company', $company));
        $first = $shops->first();

        return [
            'store' => $store === null ? null : self::store($store),
            'template' => $store === null ? null : self::template($store->template),
            'colours' => array_map(fn (string $name, string $hex) => ['name' => $name, 'hex' => $hex], array_keys(self::COLOURS), self::COLOURS),
            'sectionOptions' => array_map(fn (StoreSection $s) => ['key' => $s->value, 'label' => $s->label(), 'hint' => self::SECTION_HINTS[$s->value]],
                StoreSection::cases()),
            'addressPrefix' => StoreAddress::prefix(),
            // OSA-14: where orders go (and customers reply) while the order email is empty: the business email, never a sign-in email.
            'orderEmailFallback' => filter_var(trim((string) $company->email), FILTER_VALIDATE_EMAIL) ? trim((string) $company->email) : null,
            'banners' => $store === null ? [] : StoreBanners::forBuilder($store),
            'bannerMax' => OnlineStoreBanner::MAX,
            'linkOptions' => BannerLink::options(),
            'featuredOptions' => FeaturedMode::options(),
            'featuredMax' => FeaturedProducts::MAX_PICKED,
            'homeDepartmentsMax' => SaveOnlineStore::MAX_HOME_DEPARTMENTS,
            'aboutMax' => SaveOnlineStore::ABOUT_MAX,
            'aboutPlaceholder' => $store === null ? null : self::ABOUT_PLACEHOLDER[$store->template->value],
            'departments' => Department::query()->where('company_id', $company->id)->orderBy('position')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => (string) $d->name])->values()->all(),
            'pickedProducts' => $store === null ? [] : self::picked($store),
            // OS-11: the aisles at the first collection shop, hidden and empty ones too (StoreAisles), and what they are built from.
            'aisles' => $store === null || $first === null ? [] : app(StoreAisles::class)->all($store, $first),
            'aisleSource' => $store->aisle_source ?? 'auto',
            'aisleSourceUsed' => $store === null || $first === null ? null : app(StoreAisles::class)->source($store, $first)->value,
            'aisleNameMax' => SaveStoreAisles::NAME_MAX,
            'checklist' => [
                'collectionShops' => $shops->count(),
                'productsShown' => $first === null ? null : OnlineCatalogueQuery::base($first)->count(),
                'productsShop' => $first?->branch->name,
                // OS-11 (audit OSA-2): the trader details still to save on "Legal & contact" before going live.
                'legalMissing' => $store === null ? array_values(StoreTrader::REQUIRED) : StoreTrader::missing($store),
            ],
            'canEdit' => $canEdit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function store(OnlineStore $store): array
    {
        return [
            'slug' => $store->slug,
            'template' => $store->template->value,
            'headline' => $store->headline,
            'brandColour' => $store->brand_colour,
            'logoUrl' => StoreImages::url($store->logo_path),
            'bannerUrl' => StoreImages::url($store->banner_path),
            'sections' => self::sections($store->sections),
            'orderEmail' => $store->order_email,
            'isLive' => $store->is_live,
            'takingOrders' => $store->taking_orders,
            'liveAt' => $store->live_at?->toIso8601ZuluString(),
            'address' => StoreAddress::of($store),
            'aboutText' => $store->about_text,
            'featuredMode' => FeaturedMode::fromStored($store->featured_mode)->value,
            'featuredProductIds' => FeaturedProducts::pickedIds($store->featured_product_ids),
            'homeDepartments' => FeaturedProducts::pickedIds($store->home_departments),
        ];
    }

    /**
     * The hand-picked products' names and photos, in order (products no longer on the tills are left out).
     *
     * @return list<array{id: string, name: string, photoUrl: string|null}>
     */
    private static function picked(OnlineStore $store): array
    {
        $ids = FeaturedProducts::pickedIds($store->featured_product_ids);
        $names = $ids === [] ? collect() : Product::query()->where('company_id', $store->company_id)->whereIn('id', $ids)->pluck('name', 'id');
        $photos = $ids === [] ? [] : app(StorePhotos::class)->products($store, $ids);

        return array_values(array_map(fn (string $id) => ['id' => $id, 'name' => (string) $names[$id], 'photoUrl' => $photos[$id] ?? null],
            array_filter($ids, fn (string $id) => isset($names[$id]))));
    }

    /**
     * The saved sections in their order, then any section added since (off).
     *
     * @param  list<array{key: string, enabled: bool}>|null  $saved
     * @return list<array{key: string, enabled: bool}>
     */
    public static function sections(?array $saved): array
    {
        $out = [];

        foreach ($saved ?? StoreSection::defaults() as $section) {
            if (StoreSection::tryFrom((string) $section['key']) !== null) {
                $out[$section['key']] = ['key' => (string) $section['key'], 'enabled' => (bool) $section['enabled']];
            }
        }

        foreach (StoreSection::cases() as $case) {
            $out[$case->value] ??= ['key' => $case->value, 'enabled' => false];
        }

        return array_values($out);
    }

    /**
     * The store's look as the builder shows it: name, who it is for and its default banner photo.
     *
     * @return array{value: string, label: string, for: string, photo: string}
     */
    public static function template(StoreTemplate $template): array
    {
        return ['value' => $template->value, 'label' => $template->label(), 'for' => $template->audience(), 'photo' => asset($template->bannerPath())];
    }
}
