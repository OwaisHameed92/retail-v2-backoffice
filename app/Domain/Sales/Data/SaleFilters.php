<?php

namespace App\Domain\Sales\Data;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The sales list filters (module 4.6), read leniently from the query string (a bad value is dropped, never an error).
 *
 * - `from` / `to`: London trading days (default the last 7 days, today included);
 * - `shop`: a shop id, `all`, or absent = the top-bar shop. A one-shop user always gets their own shop;
 * - `till`, `staff` (till user id), `status` (completed | refunds | voided), `payment` (a tender name, every shop's
 *   same-named type), `min` / `max` (amount, either sign), `customer` (id, or name / card / phone text), `receipt`
 *   (receipt number prefix across every date; digits only = the sale number within the dates).
 */
final readonly class SaleFilters
{
    public const STATUSES = ['completed', 'refunds', 'voided'];

    public function __construct(
        public string $from,
        public string $to,
        public ?string $shop = null,
        public ?string $till = null,
        public ?string $staff = null,
        public ?string $status = null,
        public ?string $payment = null,
        public ?string $min = null,
        public ?string $max = null,
        public ?string $customer = null,
        public ?string $receipt = null,
        public bool $shopLocked = false,
    ) {}

    public static function fromRequest(Request $request, CurrentCompany $tenancy, ?string $currentShop): self
    {
        $today = TradingDay::today();
        $from = self::day($request->query('from')) ?? $today->subDays(6)->format('Y-m-d');
        $to = self::day($request->query('to')) ?? $today->format('Y-m-d');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $restricted = $tenancy->restrictedBranchId();
        $shopParam = $request->query('shop');
        $shop = match (true) {
            $restricted !== null => $restricted,
            $shopParam === 'all' => null,
            self::isId($shopParam) => (string) $shopParam,
            default => $currentShop,
        };
        $status = $request->query('status');
        [$min, $max] = [self::amount($request->query('min')), self::amount($request->query('max'))];

        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            [$min, $max] = [$max, $min];
        }

        return new self(
            from: $from,
            to: $to,
            shop: $shop,
            till: self::isId($request->query('till')) ? (string) $request->query('till') : null,
            staff: self::isId($request->query('staff')) ? (string) $request->query('staff') : null,
            status: in_array($status, self::STATUSES, true) ? $status : null,
            payment: self::text($request->query('payment'), 60),
            min: $min,
            max: $max,
            customer: self::text($request->query('customer'), 80),
            receipt: self::text($request->query('receipt'), 40),
            shopLocked: $restricted !== null,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $get = fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : null;

        return new self(
            from: $get('from') ?? TradingDay::today()->format('Y-m-d'),
            to: $get('to') ?? TradingDay::today()->format('Y-m-d'),
            shop: $get('shop'),
            till: $get('till'),
            staff: $get('staff'),
            status: $get('status'),
            payment: $get('payment'),
            min: $get('min'),
            max: $get('max'),
            customer: $get('customer'),
            receipt: $get('receipt'),
            shopLocked: (bool) ($data['shopLocked'] ?? false),
        );
    }

    /**
     * @return array{from: string, to: string, shop: string|null, till: string|null, staff: string|null, status: string|null, payment: string|null, min: string|null, max: string|null, customer: string|null, receipt: string|null, shopLocked: bool}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'shop' => $this->shop, 'till' => $this->till, 'staff' => $this->staff,
            'status' => $this->status, 'payment' => $this->payment, 'min' => $this->min, 'max' => $this->max,
            'customer' => $this->customer, 'receipt' => $this->receipt, 'shopLocked' => $this->shopLocked,
        ];
    }

    /** A receipt number with letters or a dash searches every date by prefix; digits only is the sale number. */
    public function receiptPrefix(): ?string
    {
        return $this->receipt !== null && preg_match('/^\d+$/', $this->receipt) !== 1 ? $this->receipt : null;
    }

    public function saleNumber(): ?int
    {
        return $this->receipt !== null && preg_match('/^\d{1,9}$/', $this->receipt) === 1 ? (int) $this->receipt : null;
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

    private static function amount(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{1,9}(\.\d{1,2})?$/', trim($value)) === 1 ? trim($value) : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
