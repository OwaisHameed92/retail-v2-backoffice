<?php

namespace App\Domain\Admin\Data;

use App\Domain\Admin\Enums\TradingCompare;
use App\Domain\Admin\Enums\TradingPeriod;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;

/**
 * What the admin trading dashboard shows (module 3.2): trading days `from … to` (Europe/London dates), the compare
 * window, and the drill-down (every business, one business, or one shop of it). Built only from the admin request
 * (`TradingDashboardRequest`); the scopes are admin scopes, never tenant ones.
 *
 * A custom range is clamped: never after today, at most MAX_DAYS long, swapped when it ends before it starts.
 */
final readonly class TradingFilters
{
    public const MAX_DAYS = 366;

    private function __construct(
        public TradingPeriod $period,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public TradingCompare $compare,
        public ?string $companyId,
        public ?string $branchId,
        public CarbonImmutable $today,
        public int $hour,
    ) {}

    public static function resolve(
        TradingPeriod $period,
        ?string $from,
        ?string $to,
        TradingCompare $compare,
        ?string $companyId = null,
        ?string $branchId = null,
        ?CarbonImmutable $now = null,
    ): self {
        $now ??= CarbonImmutable::now();
        $today = CarbonImmutable::parse(TradingDay::today($now)->format('Y-m-d'), 'UTC');
        $days = $period->days($today) ?? self::custom($from, $to, $today);

        if ($days === null) {
            $period = TradingPeriod::Last7Days;
            $days = $period->days($today);
        }

        /** @var array{0: CarbonImmutable, 1: CarbonImmutable} $days */
        return new self($period, $days[0], $days[1], $compare, $companyId, $companyId === null ? null : $branchId, $today, TradingDay::currentHour($now));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private static function custom(?string $from, ?string $to, CarbonImmutable $today): ?array
    {
        $parse = fn (?string $d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))
            ? CarbonImmutable::parse($d, 'UTC')->startOfDay()
            : null;
        [$start, $end] = [$parse($from), $parse($to)];

        if ($start === null || $end === null) {
            return null;
        }

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $end = $end->greaterThan($today) ? $today : $end;
        $start = $start->greaterThan($end) ? $end : $start;

        if ((int) $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return [$start, $end];
    }

    public function scope(): ReportScope
    {
        return ReportScope::admin($this->companyId, $this->from, $this->to, $this->branchId === null ? null : [$this->branchId]);
    }

    public function compareScope(): ?ReportScope
    {
        $scope = $this->scope();

        return match ($this->compare) {
            TradingCompare::PreviousPeriod => $scope->previousPeriod(),
            TradingCompare::SameLastWeek => $scope->sameLastWeek(),
            TradingCompare::SameLastYear => $scope->sameLastYear(),
            TradingCompare::None => null,
        };
    }

    /** A range of just today: compare up to the same hour (§2.9), charts by hour. */
    public function isToday(): bool
    {
        return $this->from->equalTo($this->today) && $this->to->equalTo($this->today);
    }

    public function singleDay(): bool
    {
        return $this->from->equalTo($this->to);
    }

    public function level(): string
    {
        return $this->branchId !== null ? 'shop' : ($this->companyId !== null ? 'business' : 'all');
    }

    public function cacheKey(): string
    {
        return 'admin-trading:v1:'.sha1(implode('|', [
            $this->from->toDateString(), $this->to->toDateString(), $this->compare->value, $this->companyId ?? '', $this->branchId ?? '',
            $this->isToday() ? (string) $this->hour : '',
        ]));
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period->value,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'compare' => $this->compare->value,
            'company' => $this->companyId,
            'branch' => $this->branchId,
            'today' => $this->today->toDateString(),
        ];
    }
}
