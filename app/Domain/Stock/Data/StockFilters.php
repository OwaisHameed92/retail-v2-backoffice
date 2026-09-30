<?php

namespace App\Domain\Stock\Data;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Stock\Support\MovementKinds;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The stock screens' filters (module 5.1), read leniently from the query string (a bad value is dropped, never an
 * error: these are bookmarkable list URLs).
 *
 * - `shop`: a shop id, `all`, or absent = the top-bar shop. A one-shop user always gets their own shop (`shopLocked`);
 * - stock on hand: `search` (part of the name, SKU prefix or exact barcode), `status` (low | out | negative),
 *   `department`, `supplier` (any of the product's suppliers);
 * - movements: `product`, `type` (a movement kind group, MovementKinds), `from` / `to` (London days, default the
 *   last 30 days);
 * - expiry: `within` (days ahead: 0 = already expired, 7, 14, 30, 60).
 */
final readonly class StockFilters
{
    public const STATUSES = ['low', 'out', 'negative'];

    public const WITHIN = [0, 7, 14, 30, 60];

    public function __construct(
        public ?string $shop = null,
        public bool $shopLocked = false,
        public ?string $search = null,
        public ?string $status = null,
        public ?string $department = null,
        public ?string $supplier = null,
        public ?string $product = null,
        public ?string $type = null,
        public ?string $from = null,
        public ?string $to = null,
        public int $within = 14,
    ) {}

    public static function fromRequest(Request $request, CurrentCompany $tenancy, ?string $currentShop): self
    {
        $restricted = $tenancy->restrictedBranchId();
        $shopParam = $request->query('shop');
        $today = TradingDay::today();
        $from = self::day($request->query('from')) ?? $today->subDays(29)->format('Y-m-d');
        $to = self::day($request->query('to')) ?? $today->format('Y-m-d');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $status = $request->query('status');
        $type = $request->query('type');
        $within = $request->query('within');

        return new self(
            shop: match (true) {
                $restricted !== null => $restricted,
                $shopParam === 'all' => null,
                self::isId($shopParam) => (string) $shopParam,
                default => $currentShop,
            },
            shopLocked: $restricted !== null,
            search: self::text($request->query('search'), 80),
            status: in_array($status, self::STATUSES, true) ? $status : null,
            department: self::isId($request->query('department')) ? (string) $request->query('department') : null,
            supplier: self::isId($request->query('supplier')) ? (string) $request->query('supplier') : null,
            product: self::isId($request->query('product')) ? (string) $request->query('product') : null,
            type: is_string($type) && array_key_exists($type, MovementKinds::GROUPS) ? $type : null,
            from: $from,
            to: $to,
            within: is_numeric($within) && in_array((int) $within, self::WITHIN, true) ? (int) $within : 14,
        );
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function toArray(): array
    {
        return [
            'shop' => $this->shop ?? ($this->shopLocked ? null : 'all'), 'shopLocked' => $this->shopLocked,
            'search' => $this->search, 'status' => $this->status, 'department' => $this->department,
            'supplier' => $this->supplier, 'product' => $this->product, 'type' => $this->type,
            'from' => $this->from, 'to' => $this->to, 'within' => $this->within,
        ];
    }

    public static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-Za-z]{26}$/', $value) === 1;
    }

    private static function day(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== null && $day->format('Y-m-d') === $value ? $value : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(str_replace(['%', '\\'], '', $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
