<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * Which products the home page's "Featured products" row shows (OS-8, `online_stores.featured_mode`; null reads as
 * best sellers). Best sellers with no sales yet fall back to the newest products (FeaturedProducts).
 */
enum FeaturedMode: string
{
    case BestSellers = 'bestSellers';
    case HandPicked = 'handPicked';
    case Newest = 'newest';

    public function label(): string
    {
        return match ($this) {
            self::BestSellers => 'Best sellers',
            self::HandPicked => 'Hand-picked',
            self::Newest => 'Newest',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::BestSellers => 'What sold most at your collection shops in the last 30 days, from your tills. Newest products until there are sales.',
            self::HandPicked => 'Up to 24 products you choose, in your order.',
            self::Newest => 'The products most recently added on your tills.',
        };
    }

    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::BestSellers) : self::BestSellers;
    }

    /**
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label(), 'hint' => $m->hint()], self::cases());
    }
}
