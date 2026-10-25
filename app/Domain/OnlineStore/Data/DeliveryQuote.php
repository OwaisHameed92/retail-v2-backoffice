<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\DeliveryArea;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;

/**
 * What delivering a basket from a shop to a postcode comes to (OS-9), worked out on the server every time: whether
 * the shop delivers there, the fee (free from the shop's free-over amount), the minimum order and how much is still
 * missing to reach it. `problem` says in words why it cannot be delivered (null when it can).
 */
final readonly class DeliveryQuote
{
    public function __construct(
        public bool $offered,
        public ?string $postcode,
        public bool $covered,
        public string $fee,
        public ?string $freeOver,
        public ?string $minimum,
        public ?string $shortBy,
        public ?string $problem,
    ) {}

    public static function for(OnlineStoreShop $shop, ?string $postcode, string $itemsTotal): self
    {
        $offered = $shop->delivers();
        $code = $postcode === null || trim($postcode) === '' ? null : DeliveryArea::postcode($postcode);
        $covered = $offered && $code !== null && DeliveryArea::covers($shop->delivery_areas, $code);
        $free = $shop->delivery_free_over !== null && Money::compare($itemsTotal, $shop->delivery_free_over) >= 0;
        $minimum = $shop->delivery_minimum !== null && Money::compare($shop->delivery_minimum, '0') > 0 ? $shop->delivery_minimum : null;
        $short = $minimum !== null && Money::compare($itemsTotal, $minimum) < 0 ? Money::sub($minimum, $itemsTotal, 2) : null;

        // OS-12: a delivery-only shop never suggests click & collect.
        $collect = $shop->collects();
        $problem = match (true) {
            ! $offered => $collect ? 'This shop does not deliver. Choose click & collect.' : 'This shop does not deliver right now.',
            $postcode === null || trim($postcode) === '' => 'Enter your postcode to see if we deliver to you.',
            $code === null => 'Enter a full postcode, like '.self::example().'.',
            ! $covered => "Sorry, we do not deliver to {$code} yet.".($collect ? ' Choose click & collect instead.' : ''),
            $short !== null => 'The minimum order for delivery is '.MoneyFormat::format($minimum).'. Add '.MoneyFormat::format($short).' more to have it delivered.',
            default => null,
        };

        return new self($offered, $code, $covered, $free ? '0.00' : Money::normalise($shop->delivery_fee, 2), $shop->delivery_free_over, $minimum, $short, $problem);
    }

    public function deliverable(): bool
    {
        return $this->problem === null;
    }

    /**
     * @return array{offered: bool, postcode: string|null, covered: bool, fee: string, freeOver: string|null, minimum: string|null, shortBy: string|null, problem: string|null}
     */
    public function toArray(): array
    {
        return [
            'offered' => $this->offered, 'postcode' => $this->postcode, 'covered' => $this->covered, 'fee' => $this->fee,
            'freeOver' => $this->freeOver, 'minimum' => $this->minimum, 'shortBy' => $this->shortBy, 'problem' => $this->problem,
        ];
    }

    private static function example(): string
    {
        return app(Country::class)->address()['postcodeExample'];
    }
}
