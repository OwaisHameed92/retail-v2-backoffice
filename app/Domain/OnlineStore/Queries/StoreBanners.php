<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Enums\BannerLink;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreBanner;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Collection;

/**
 * A store's home banners (OS-8), for the public home page (slides) and the store builder (the list it edits).
 *
 * Slides: the banners that are on, in order, each with its photo (else the template's), headline, text and button
 * (a department shown at the shop, a product, a search or an https address; none = the template's own button). A
 * store with no banners at all shows its old single banner and headline (`online_stores.banner_path` / `headline`,
 * before OS-8), else the template's photo; a store whose banners are all off shows the template's photo.
 */
final class StoreBanners
{
    /**
     * @param  list<string>  $departmentIds  the departments shown at the visitor's shop (a link to another goes to "all products")
     * @return list<array{id: string, photoUrl: string, headline: string|null, text: string|null, buttonLabel: string|null, href: string|null, external: bool}>
     */
    public static function slides(OnlineStore $store, array $departmentIds): array
    {
        $banners = self::of($store);
        $default = asset($store->template->bannerPath());

        if ($banners->isEmpty()) {
            return [self::slide('main', StoreImages::url($store->banner_path) ?? $default, self::text($store->headline), null, null, null, false)];
        }

        $on = $banners->filter(fn (OnlineStoreBanner $b) => $b->is_on)->values();

        if ($on->isEmpty()) {
            return [self::slide('main', $default, null, null, null, null, false)];
        }

        return $on->map(function (OnlineStoreBanner $b) use ($default, $departmentIds) {
            [$href, $external] = self::href($b, $departmentIds);

            return self::slide($b->id, StoreImages::url($b->photo_path) ?? $default, self::text($b->headline), self::text($b->text),
                self::text($b->button_label), $href, $external);
        })->all();
    }

    /**
     * The builder's list: every banner in order with what its link points at.
     *
     * @return list<array<string, mixed>>
     */
    public static function forBuilder(OnlineStore $store): array
    {
        $banners = self::of($store);
        $ids = fn (BannerLink $type) => $banners->filter(fn (OnlineStoreBanner $b) => $b->link_type === $type)->pluck('link_target')->filter()->all();
        $departments = Department::query()->where('company_id', $store->company_id)->whereIn('id', $ids(BannerLink::Department))->pluck('name', 'id');
        $products = Product::query()->where('company_id', $store->company_id)->whereIn('id', $ids(BannerLink::Product))->pluck('name', 'id');

        return $banners->map(fn (OnlineStoreBanner $b) => [
            'id' => $b->id,
            'position' => $b->position,
            'photoUrl' => StoreImages::url($b->photo_path),
            'headline' => $b->headline,
            'text' => $b->text,
            'buttonLabel' => $b->button_label,
            'linkType' => $b->link_type->value,
            'linkTarget' => $b->link_target,
            'linkLabel' => match ($b->link_type) {
                BannerLink::Department => $departments[$b->link_target] ?? 'A department that no longer exists',
                BannerLink::Product => $products[$b->link_target] ?? 'A product that no longer exists',
                BannerLink::Search, BannerLink::Url => $b->link_target,
                BannerLink::None => null,
            },
            'isOn' => $b->is_on,
        ])->values()->all();
    }

    /**
     * @return Collection<int, OnlineStoreBanner>
     */
    public static function of(OnlineStore $store): Collection
    {
        return StoreScope::run($store, fn () => OnlineStoreBanner::query()->where('company_id', $store->company_id)
            ->where('online_store_id', $store->id)->orderBy('position')->orderBy('id')->get()->toBase());
    }

    /**
     * @param  list<string>  $departmentIds
     * @return array{0: string|null, 1: bool}
     */
    private static function href(OnlineStoreBanner $b, array $departmentIds): array
    {
        $target = trim((string) $b->link_target);

        return match ($b->link_type) {
            BannerLink::Department => [in_array($target, $departmentIds, true)
                ? route('store.department', ['department' => $target], false) : route('store.search', [], false), false],
            BannerLink::Product => [preg_match('/^[0-9A-Za-z]{26}$/', $target) === 1 ? route('store.product', ['product' => $target], false) : null, false],
            BannerLink::Search => [route('store.search', $target === '' ? [] : ['q' => $target], false), false],
            BannerLink::Url => str_starts_with($target, 'https://') ? [$target, true] : [null, false],
            BannerLink::None => [null, false],
        };
    }

    /**
     * @return array{id: string, photoUrl: string, headline: string|null, text: string|null, buttonLabel: string|null, href: string|null, external: bool}
     */
    private static function slide(string $id, string $photoUrl, ?string $headline, ?string $text, ?string $buttonLabel, ?string $href, bool $external): array
    {
        return compact('id', 'photoUrl', 'headline', 'text', 'buttonLabel', 'href', 'external');
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
