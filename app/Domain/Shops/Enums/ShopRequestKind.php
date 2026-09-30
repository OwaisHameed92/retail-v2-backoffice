<?php

namespace App\Domain\Shops\Enums;

/**
 * What a business asks Switch & Save for from Shops and tills (module 4.7). camelCase values.
 */
enum ShopRequestKind: string
{
    case MoreTills = 'moreTills';
    case NewShop = 'newShop';

    public function label(): string
    {
        return match ($this) {
            self::MoreTills => 'More tills',
            self::NewShop => 'Another shop',
        };
    }
}
