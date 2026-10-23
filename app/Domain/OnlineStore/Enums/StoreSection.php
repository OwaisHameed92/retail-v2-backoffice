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

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Banner and headline',
            self::Departments => 'Departments',
            self::Featured => 'Featured products',
            self::HowItWorks => 'How click & collect works',
            self::Shops => 'Shops and opening hours',
            self::About => 'About us',
        };
    }

    /**
     * A new store's sections: all on, in this order.
     *
     * @return list<array{key: string, enabled: bool}>
     */
    public static function defaults(): array
    {
        return array_map(fn (self $s) => ['key' => $s->value, 'enabled' => true], self::cases());
    }
}
