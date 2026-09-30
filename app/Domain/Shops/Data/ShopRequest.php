<?php

namespace App\Domain\Shops\Data;

use App\Domain\Shops\Enums\ShopRequestKind;

/**
 * "Ask for more tills / another shop" (module 4.7): what the business asked for. `branchId` is the shop that needs
 * more tills (MoreTills only); `newShopName` names the shop to add (NewShop only).
 */
final readonly class ShopRequest
{
    public const MAX_TILLS = 20;

    public function __construct(
        public ShopRequestKind $kind,
        public int $tills,
        public ?string $branchId = null,
        public ?string $newShopName = null,
        public ?string $message = null,
        public ?string $phone = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated snake_case input
     */
    public static function fromArray(array $input): self
    {
        $text = fn (string $key) => isset($input[$key]) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;
        $kind = ShopRequestKind::from((string) $input['kind']);

        return new self(
            kind: $kind,
            tills: (int) $input['tills'],
            branchId: $kind === ShopRequestKind::MoreTills ? $text('branch_id') : null,
            newShopName: $kind === ShopRequestKind::NewShop ? $text('new_shop_name') : null,
            message: $text('message'),
            phone: $text('phone'),
        );
    }
}
