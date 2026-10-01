<?php

namespace App\Domain\Labels\Enums;

/**
 * Why a product is in a shop's label queue (the latest reason wins when it is queued again before printing).
 */
enum LabelReason: string
{
    case PriceChange = 'priceChange';
    case ShopPrice = 'shopPrice';
    case ShopPriceEnded = 'shopPriceEnded';
    case PromotionStarted = 'promotionStarted';
    case PromotionEnded = 'promotionEnded';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::PriceChange => 'Price change',
            self::ShopPrice => 'Shop price',
            self::ShopPriceEnded => 'Shop price ended',
            self::PromotionStarted => 'Offer started',
            self::PromotionEnded => 'Offer ended',
            self::Manual => 'Added by hand',
        };
    }
}
