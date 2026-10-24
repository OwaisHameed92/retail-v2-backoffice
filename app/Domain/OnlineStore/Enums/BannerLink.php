<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * Where a home banner's button goes (OS-8). `none`: the template's own "Start shopping" button.
 */
enum BannerLink: string
{
    case None = 'none';
    case Department = 'department';
    case Product = 'product';
    case Search = 'search';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Start shopping (default)',
            self::Department => 'A department',
            self::Product => 'A product',
            self::Search => 'A search',
            self::Url => 'A web address',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $l) => ['value' => $l->value, 'label' => $l->label()], self::cases());
    }
}
