<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Enums\StoreSection;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\OnlineCatalogueQuery;
use App\Domain\OnlineStore\Support\StoreAddress;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Tenancy\Models\Company;

/**
 * The portal's store builder page (OS-3, `app/online-store/builder`): the business's store settings as React edits
 * them, its template (read only: Switch & Save sets it when the add-on is switched on) with its default banner
 * photo, the preset brand colours, the address prefix for the live preview and the "ready to go live" checklist
 * (collection shops, products shown at the first one). No store yet → `store` and `template` are null and the page
 * says the online store is not switched on.
 */
final class StoreBuilderPage
{
    /** Preset main colours (name => hex); any other #RRGGBB can be typed. */
    public const COLOURS = ['Forest' => '#0B4A34', 'Red' => '#B42318', 'Blue' => '#1F4E9C', 'Purple' => '#5B2A86', 'Charcoal' => '#2A2522'];

    /** What each home section shows. */
    private const SECTION_HINTS = [
        'hero' => 'Your banner photo and headline at the top.',
        'departments' => 'Tiles for each department with products online.',
        'featured' => 'A row of products to start shopping.',
        'howItWorks' => 'Order online, pick a time, pay at the shop.',
        'shops' => 'Your collection shops with their opening hours.',
        'about' => 'A short note about your business.',
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
            'checklist' => [
                'collectionShops' => $shops->count(),
                'productsShown' => $first === null ? null : OnlineCatalogueQuery::base($first)->count(),
                'productsShop' => $first?->branch->name,
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
        ];
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
