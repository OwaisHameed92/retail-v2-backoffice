<?php

namespace App\Domain\Cash\Data;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The Cash and Z screens' filters (module 5.4), read leniently from the query string (a bad value is dropped).
 *
 * - `from` / `to`: London trading days (default the last 7 days, today included; at most MAX_DAYS apart);
 * - `shop`: a shop id, `all`, or absent = the top-bar shop. A one-shop user always gets their own shop;
 * - `till`: a till id; `status`: `open` | `closed` (shifts); `threshold`: an alert amount in pounds (alerts).
 */
final readonly class CashFilters
{
    public const MAX_DAYS = 92;

    public const STATUSES = ['open', 'closed'];

    public function __construct(
        public string $from,
        public string $to,
        public ?string $shop = null,
        public ?string $till = null,
        public ?string $status = null,
        public ?string $threshold = null,
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

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_DAYS) {
            $from = CarbonImmutable::parse($to)->subDays(self::MAX_DAYS - 1)->format('Y-m-d');
        }

        $restricted = $tenancy->restrictedBranchId();
        $shopParam = $request->query('shop');
        $status = $request->query('status');
        $threshold = $request->query('threshold');

        return new self(
            from: $from,
            to: $to,
            shop: match (true) {
                $restricted !== null => $restricted,
                $shopParam === 'all' => null,
                self::isId($shopParam) => (string) $shopParam,
                default => $currentShop,
            },
            till: self::isId($request->query('till')) ? (string) $request->query('till') : null,
            status: in_array($status, self::STATUSES, true) ? $status : null,
            threshold: is_string($threshold) && preg_match('/^\d{1,5}(\.\d{1,2})?$/', trim($threshold)) === 1 ? Money::normalise(trim($threshold)) : null,
            shopLocked: $restricted !== null,
        );
    }

    /**
     * The UTC window [start, end) of the chosen London days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(): array
    {
        return [TradingDay::window($this->from)[0], TradingDay::window($this->to)[1]];
    }

    /**
     * Narrow a till row query to the shop and till picked (a one-shop user is already pinned in `shop`).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, bool $hasTill = true): Builder
    {
        $model = $query->getModel();

        return $query
            ->when($this->shop !== null, fn (Builder $q) => $q->where($model->qualifyColumn('branch_id'), $this->shop))
            ->when($hasTill && $this->till !== null, fn (Builder $q) => $q->where($model->qualifyColumn('register_id'), $this->till));
    }

    /**
     * Narrow to rows whose UTC `$column` falls in the chosen London days.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function during(Builder $query, string $column): Builder
    {
        [$start, $end] = $this->window();
        $qualified = $query->getModel()->qualifyColumn($column);

        return $query->where($qualified, '>=', $start->format('Y-m-d H:i:s'))->where($qualified, '<', $end->format('Y-m-d H:i:s'));
    }

    /**
     * Narrow to rows whose trading date column (a local day, `Y-m-d`) is one of the chosen days.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function onDays(Builder $query, string $column = 'trading_date'): Builder
    {
        $qualified = $query->getModel()->qualifyColumn($column);

        return $query->where($qualified, '>=', $this->from)->where($qualified, '<', CarbonImmutable::parse($this->to)->addDay()->format('Y-m-d'));
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'shop' => $this->shop, 'till' => $this->till,
            'status' => $this->status, 'threshold' => $this->threshold, 'shopLocked' => $this->shopLocked,
        ];
    }

    private static function day(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-Za-z]{26}$/', $value) === 1;
    }
}
