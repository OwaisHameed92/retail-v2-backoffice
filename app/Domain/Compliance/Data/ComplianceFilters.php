<?php

namespace App\Domain\Compliance\Data;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The Compliance screens' filters (module 5.7), read leniently from the query string (a bad value is dropped).
 *
 * - `from` / `to`: London days (default the last 30 days, today included; at most MAX_DAYS apart);
 * - `shop`: a shop id, `all`, or absent = the top-bar shop. A one-shop user always gets their own shop;
 * - `staff`: a till user id; `rule`: an AgeRule value; `type`: an exception type, incident category or training topic;
 * - `status`: `valid` | `expiring` | `expired` (training, licences) or `open` | `closed` (recalls).
 */
final readonly class ComplianceFilters
{
    public const MAX_DAYS = 366;

    public const DEFAULT_DAYS = 30;

    public const STATUSES = ['valid', 'expiring', 'expired', 'open', 'closed'];

    public function __construct(
        public string $from,
        public string $to,
        public ?string $shop = null,
        public ?string $staff = null,
        public ?string $rule = null,
        public ?string $type = null,
        public ?string $status = null,
        public bool $shopLocked = false,
    ) {}

    public static function fromRequest(Request $request, CurrentCompany $tenancy, ?string $currentShop): self
    {
        $today = TradingDay::today();
        $from = self::day($request->query('from')) ?? $today->subDays(self::DEFAULT_DAYS - 1)->format('Y-m-d');
        $to = self::day($request->query('to')) ?? $today->format('Y-m-d');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_DAYS) {
            $from = CarbonImmutable::parse($to)->subDays(self::MAX_DAYS - 1)->format('Y-m-d');
        }

        $restricted = $tenancy->restrictedBranchId();
        $shop = $request->query('shop');

        return new self(
            from: $from,
            to: $to,
            shop: match (true) {
                $restricted !== null => $restricted,
                $shop === 'all' => null,
                self::isId($shop) => (string) $shop,
                default => $currentShop,
            },
            staff: self::text($request->query('staff'), '/^[0-9A-Za-z_-]{1,64}$/'),
            rule: self::text($request->query('rule'), '/^[A-Za-z0-9]{1,40}$/'),
            type: self::text($request->query('type'), '/^[\pL\pN _.\/&-]{1,100}$/u'),
            status: in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : null,
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
     * Narrow a till row query to the shop picked (a one-shop user is already pinned in `shop`).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, string $column = 'branch_id'): Builder
    {
        return $query->when($this->shop !== null, fn (Builder $q) => $q->where($q->getModel()->qualifyColumn($column), $this->shop));
    }

    /**
     * Narrow to rows whose UTC `$column` falls in the chosen London days.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function during(Builder $query, string $column): Builder
    {
        [$start, $end] = $this->window();
        $qualified = str_contains($column, '.') ? $column : $query->getModel()->qualifyColumn($column);

        return $query->where($qualified, '>=', $start->format('Y-m-d H:i:s'))->where($qualified, '<', $end->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'shop' => $this->shop, 'staff' => $this->staff, 'rule' => $this->rule,
            'type' => $this->type, 'status' => $this->status, 'shopLocked' => $this->shopLocked,
        ];
    }

    private static function text(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
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
