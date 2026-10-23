<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * The online store's look (DECISIONS "Online store, click & collect first"). All three are light designs.
 */
enum StoreTemplate: string
{
    case FreshMarket = 'freshMarket';
    case Butcher = 'butcher';
    case Pharmacy = 'pharmacy';

    public function label(): string
    {
        return match ($this) {
            self::FreshMarket => 'Fresh Market',
            self::Butcher => 'Butcher\'s Block',
            self::Pharmacy => 'Care Pharmacy',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
