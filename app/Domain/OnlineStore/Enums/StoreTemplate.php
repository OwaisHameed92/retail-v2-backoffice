<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * The online store's look (DECISIONS "Online store, click & collect first"). Both are light designs. Switch & Save
 * sets it when the add-on is switched on; the business cannot change it. Each template has its own default brand
 * colour (the store's own colour wins) and default banner photo (the store's own banner wins).
 *
 * A stored value that is not a case (for example the removed `pharmacy`) reads as Fresh Market (StoreTemplateCast).
 */
enum StoreTemplate: string
{
    case FreshMarket = 'freshMarket';
    case Butcher = 'butcher';

    public function label(): string
    {
        return match ($this) {
            self::FreshMarket => 'Fresh Market',
            self::Butcher => 'Butcher\'s Block',
        };
    }

    /** Who the template is for. */
    public function audience(): string
    {
        return match ($this) {
            self::FreshMarket => 'Grocery, convenience and world foods',
            self::Butcher => 'Butchers, halal meat and BBQ',
        };
    }

    /** "#0B4A34": the main colour when the store has not chosen one. */
    public function defaultBrand(): string
    {
        return match ($this) {
            self::FreshMarket => '#0B4A34',
            self::Butcher => '#8F1D1D',
        };
    }

    /** The default banner photo, a path under `public/`. */
    public function bannerPath(): string
    {
        return match ($this) {
            self::FreshMarket => 'images/online-store/hero-grocery.jpg',
            self::Butcher => 'images/online-store/hero-butcher.jpg',
        };
    }

    /** The template for a stored value; anything unknown is Fresh Market. */
    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::FreshMarket) : self::FreshMarket;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
