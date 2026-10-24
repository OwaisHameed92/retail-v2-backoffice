<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * The home page sections a store can turn on or off and put in order (`online_stores.sections`). Every template
 * draws them in its own style.
 */
enum StoreSection: string
{
    case Hero = 'hero';
    case Departments = 'departments';
    case Featured = 'featured';
    case HowItWorks = 'howItWorks';
    case Shops = 'shops';
    case About = 'about';
    case Offers = 'offers';
    case DepartmentRows = 'departmentRows';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Banner and headline',
            self::Departments => 'Departments',
            self::Featured => 'Featured products',
            self::HowItWorks => 'How click & collect works',
            self::Shops => 'Shops and opening hours',
            self::About => 'About us',
            self::Offers => 'On offer',
            self::DepartmentRows => 'Rows by department',
        };
    }

    /** OS-8: extra home rows the business turns on when it wants them (off and last until then). */
    public function optional(): bool
    {
        return $this === self::Offers || $this === self::DepartmentRows;
    }

    /**
     * A new store's sections: the main ones, all on, in this order (the optional rows are added off by the builder).
     *
     * @return list<array{key: string, enabled: bool}>
     */
    public static function defaults(): array
    {
        return array_values(array_map(fn (self $s) => ['key' => $s->value, 'enabled' => true], array_filter(self::cases(), fn (self $s) => ! $s->optional())));
    }
}
