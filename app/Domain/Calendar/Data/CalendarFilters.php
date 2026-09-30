<?php

namespace App\Domain\Calendar\Data;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Http\Request;

/**
 * The calendar filters (module 5.9), read leniently from the query string: `shop` (a one-shop user is pinned to
 * theirs; `all` = every shop; default the shop picked in the top bar) and `when` (`upcoming` from today, `past`,
 * or `all`). `today` is the London day.
 */
final readonly class CalendarFilters
{
    public const WHEN = ['upcoming', 'past', 'all'];

    public function __construct(
        public ?string $shop,
        public string $when,
        public string $today,
        public bool $shopLocked = false,
    ) {}

    public static function fromRequest(Request $request, CurrentCompany $tenancy, ?string $currentShop): self
    {
        $restricted = $tenancy->restrictedBranchId();
        $shop = $request->query('shop');
        $when = $request->query('when');

        return new self(
            shop: match (true) {
                $restricted !== null => $restricted,
                $shop === 'all' => null,
                is_string($shop) && preg_match('/^[0-9A-Za-z]{26}$/', $shop) === 1 => $shop,
                default => $currentShop,
            },
            when: in_array($when, self::WHEN, true) ? (string) $when : 'upcoming',
            today: TradingDay::today()->format('Y-m-d'),
            shopLocked: $restricted !== null,
        );
    }

    /**
     * @return array{shop: string|null, when: string, today: string, shopLocked: bool}
     */
    public function toArray(): array
    {
        return ['shop' => $this->shop, 'when' => $this->when, 'today' => $this->today, 'shopLocked' => $this->shopLocked];
    }
}
