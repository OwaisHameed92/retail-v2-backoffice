<?php

namespace App\Domain\News\Support;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\NewsTitle;

/**
 * Who may do what with news titles (module 5.8). `news.manage` edits titles; a title for every shop (`branch_id`
 * blank) is company-wide, so only a user of every shop (owner, every-shop manager) may create, change, move or archive
 * it. A one-shop user sees their shop's titles and the every-shop ones, and changes only their own shop's.
 */
final class NewsAccess
{
    public static function canManage(): bool
    {
        return app(CurrentCompany::class)->can(Ability::NewsManage);
    }

    /** May name `$shop` (null = every shop) as a title's shop. */
    public static function mayUseShop(?string $shop): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return $restricted === null || ($shop !== null && $shop === $restricted);
    }

    /** May look at the title: every shop's titles for everyone; a shop's own title only for a user of that shop. */
    public static function mayView(NewsTitle $title): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();
        $shop = self::shopOf($title);

        return $restricted === null || $shop === null || $shop === $restricted;
    }

    public static function mayEdit(NewsTitle $title): bool
    {
        return self::canManage() && self::mayUseShop(self::shopOf($title));
    }

    public static function shopOf(NewsTitle $title): ?string
    {
        $shop = $title->branch_id;

        return is_string($shop) && $shop !== '' ? $shop : null;
    }
}
