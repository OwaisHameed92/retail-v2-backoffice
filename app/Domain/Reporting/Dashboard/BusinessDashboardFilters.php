<?php

namespace App\Domain\Reporting\Dashboard;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Reporting\Support\TradingRange;
use Carbon\CarbonImmutable;

/**
 * What the business dashboard shows (module 3.3, DASHBOARD.md §2.1): trading days `from … to`, the compare window,
 * and the shop (all shops, or one) and till (all, or one of that shop). Always a **tenant** scope of the current
 * company; the shop is resolved by {@see BusinessContext} (a one-shop user is fixed to their shop), never taken
 * raw from the request.
 */
final readonly class BusinessDashboardFilters implements SalesWindow
{
    private function __construct(
        public string $companyId,
        public TradingPeriod $period,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public TradingCompare $compare,
        public ?string $branchId,
        public ?string $registerId,
        public CarbonImmutable $today,
        public int $hour,
    ) {}

    public static function resolve(
        string $companyId,
        TradingPeriod $period,
        ?string $from,
        ?string $to,
        TradingCompare $compare,
        ?string $branchId = null,
        ?string $registerId = null,
        ?CarbonImmutable $now = null,
    ): self {
        $now ??= CarbonImmutable::now();
        $today = TradingRange::today($now);
        [$period, $start, $end] = TradingRange::resolve($period, $from, $to, $today);

        return new self($companyId, $period, $start, $end, $compare, $branchId, $branchId === null ? null : $registerId, $today, TradingDay::currentHour($now));
    }

    public function scope(): ReportScope
    {
        return ReportScope::tenant($this->from, $this->to, $this->branchIds(), $this->registerId === null ? null : [$this->registerId]);
    }

    /** The chosen shop's scope without the till filter (its tills leaderboard). */
    public function shopScope(): ReportScope
    {
        return ReportScope::tenant($this->from, $this->to, $this->branchIds());
    }

    public function compareScope(): ?ReportScope
    {
        return $this->compare->window($this->scope());
    }

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

    /** 'business' (every shop), 'shop' (one shop, every till) or 'till'. */
    public function level(): string
    {
        return $this->registerId !== null ? 'till' : ($this->branchId !== null ? 'shop' : 'business');
    }

    /**
     * @return list<string>|null
     */
    public function branchIds(): ?array
    {
        return $this->branchId === null ? null : [$this->branchId];
    }

    public function cacheKey(): string
    {
        return 'business-dashboard:v1:'.sha1(implode('|', [
            $this->companyId, $this->from->toDateString(), $this->to->toDateString(), $this->compare->value,
            $this->branchId ?? '', $this->registerId ?? '', $this->isToday() ? (string) $this->hour : '',
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
            'branch' => $this->branchId,
            'till' => $this->registerId,
            'today' => $this->today->toDateString(),
        ];
    }
}
