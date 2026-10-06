<?php

namespace App\Domain\Admin\Data;

use App\Domain\Reporting\Dashboard\SalesWindow;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Reporting\Support\TradingRange;
use Carbon\CarbonImmutable;

/**
 * What the admin trading dashboard shows (module 3.2): trading days `from … to` (dates in the shops' time zone), the compare
 * window, and the drill-down (every business, one business, or one shop of it). Built only from the admin request
 * (`TradingDashboardRequest`); the scopes are admin scopes, never tenant ones.
 *
 * A custom range is clamped by {@see TradingRange}.
 */
final readonly class TradingFilters implements SalesWindow
{
    public const MAX_DAYS = TradingRange::MAX_DAYS;

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
        $today = TradingRange::today($now);
        [$period, $start, $end] = TradingRange::resolve($period, $from, $to, $today);

        return new self($period, $start, $end, $compare, $companyId, $companyId === null ? null : $branchId, $today, TradingDay::currentHour($now));
    }

    public function scope(): ReportScope
    {
        return ReportScope::admin($this->companyId, $this->from, $this->to, $this->branchId === null ? null : [$this->branchId]);
    }

    public function compareScope(): ?ReportScope
    {
        return $this->compare->window($this->scope());
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

    public function currentHour(): int
    {
        return $this->hour;
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
