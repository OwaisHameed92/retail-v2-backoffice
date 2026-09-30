<?php

namespace App\Domain\StaffTime\Data;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\StaffTime\Support\HoursMath;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Staff time filters (module 5.6), read leniently from the query string (a bad value is dropped):
 *
 * - `from` / `to`: London days (default this week, Monday to Sunday; at most MAX_DAYS apart). The rota reads `week`
 *   (any day of it; default this week) instead;
 * - `shop`: a shop id, `all`, or absent = the top-bar shop. A one-shop user always gets their own shop;
 * - `till`, `person`: ids; `problems`: `1` = only shifts with a missing clock or open break (clock events);
 * - `rounding`: 0, 5, 10 or 15 minutes per shift; `overtime`: weekly hours after which time is overtime (1–168,
 *   halves allowed; none by default: the till has no overtime rule); `group`: `week` (default) or `period`.
 */
final readonly class TimeFilters
{
    public const MAX_DAYS = 92;

    public function __construct(
        public string $from,
        public string $to,
        public ?string $shop = null,
        public ?string $till = null,
        public ?string $person = null,
        public int $rounding = 0,
        public ?string $overtime = null,
        public string $group = 'week',
        public bool $problems = false,
        public bool $shopLocked = false,
    ) {}

    public static function fromRequest(Request $request, CurrentCompany $tenancy, ?string $currentShop): self
    {
        $week = TradingDay::today()->startOfWeek(CarbonImmutable::MONDAY);
        $from = self::day($request->query('from')) ?? $week->toDateString();
        $to = self::day($request->query('to')) ?? $week->addDays(6)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_DAYS) {
            $from = CarbonImmutable::parse($to)->subDays(self::MAX_DAYS - 1)->toDateString();
        }

        $restricted = $tenancy->restrictedBranchId();
        $shop = $request->query('shop');
        $rounding = $request->query('rounding');
        $overtime = $request->query('overtime');

        return new self(
            from: $from,
            to: $to,
            shop: match (true) {
                $restricted !== null => $restricted,
                $shop === 'all' => null,
                self::isId($shop) => (string) $shop,
                default => $currentShop,
            },
            till: self::isId($request->query('till')) ? (string) $request->query('till') : null,
            person: self::isId($request->query('person'), true) ? (string) $request->query('person') : null,
            rounding: is_string($rounding) && in_array((int) $rounding, HoursMath::ROUNDINGS, true) && ctype_digit($rounding) ? (int) $rounding : 0,
            overtime: is_string($overtime) && preg_match('/^\d{1,3}(\.[05])?$/', trim($overtime)) === 1 && (float) $overtime >= 1 && (float) $overtime <= 168
                ? rtrim(rtrim(trim($overtime), '0'), '.') : null,
            group: $request->query('group') === 'period' ? 'period' : 'week',
            problems: $request->query('problems') === '1',
            shopLocked: $restricted !== null,
        );
    }

    /** The rota's week: Monday of the `week` day, else of `from` (kept from another tab), else this week. */
    public static function rotaWeek(Request $request): string
    {
        $day = self::day($request->query('week')) ?? self::day($request->query('from')) ?? TradingDay::today()->toDateString();

        return CarbonImmutable::parse($day, 'UTC')->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    /** The same filters for other days (the rota's week). */
    public function withDays(string $from, string $to): self
    {
        return new self($from, $to, $this->shop, $this->till, $this->person, $this->rounding, $this->overtime, $this->group, $this->problems, $this->shopLocked);
    }

    /** The overtime threshold in minutes, or null. */
    public function overtimeMinutes(): ?int
    {
        return $this->overtime === null ? null : (int) round((float) $this->overtime * 60);
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
     * Whole London weeks around the chosen days ("Y-m-d" Monday, "Y-m-d" Sunday): overtime needs the full week.
     *
     * @return array{0: string, 1: string}
     */
    public function weeks(): array
    {
        return [
            CarbonImmutable::parse($this->from, 'UTC')->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            CarbonImmutable::parse($this->to, 'UTC')->endOfWeek(CarbonImmutable::SUNDAY)->toDateString(),
        ];
    }

    public function includes(string $day): bool
    {
        return $day >= $this->from && $day <= $this->to;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'shop' => $this->shop, 'till' => $this->till, 'person' => $this->person,
            'rounding' => $this->rounding, 'overtime' => $this->overtime, 'group' => $this->group, 'problems' => $this->problems,
            'shopLocked' => $this->shopLocked,
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

    /** A shop or till id (ULID); `$person` also allows a till user's id (up to 64 letters, digits, dashes). */
    private static function isId(mixed $value, bool $person = false): bool
    {
        return is_string($value) && preg_match($person ? '/^[0-9A-Za-z-]{1,64}$/' : '/^[0-9A-Za-z]{26}$/', $value) === 1;
    }
}
