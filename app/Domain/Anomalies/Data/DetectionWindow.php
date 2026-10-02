<?php

namespace App\Domain\Anomalies\Data;

use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;

/**
 * What one detection run looks at (module 6.6): one business's active shops, the trading day examined (yesterday for
 * the daily run, today so far for the hourly run), "now" and the rolling baseline of {@see self::BASELINE_DAYS} days
 * before that day (8 weeks: 8 of each weekday).
 */
final readonly class DetectionWindow
{
    public const BASELINE_DAYS = 56;

    public const HOURLY = 'hourly';

    public const DAILY = 'daily';

    /**
     * @param  array<string, string>  $shops  active shop names by id
     */
    public function __construct(
        public string $companyId,
        public array $shops,
        public string $day,
        public CarbonImmutable $now,
        public string $mode,
    ) {}

    public function daily(): bool
    {
        return $this->mode === self::DAILY;
    }

    /** The first day of the baseline (56 days before the day examined). */
    public function baselineFrom(): string
    {
        return CarbonImmutable::parse($this->day, 'UTC')->subDays(self::BASELINE_DAYS)->toDateString();
    }

    /** The day before the day examined (end of the baseline). */
    public function baselineTo(): string
    {
        return CarbonImmutable::parse($this->day, 'UTC')->subDay()->toDateString();
    }

    /**
     * The same weekday in each of the baseline's 8 weeks.
     *
     * @return list<string>
     */
    public function sameWeekdays(): array
    {
        $day = CarbonImmutable::parse($this->day, 'UTC');

        return array_map(fn (int $w) => $day->subWeeks($w)->toDateString(), range(1, intdiv(self::BASELINE_DAYS, 7)));
    }

    /**
     * UTC [start, end) of the day examined.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcWindow(): array
    {
        return TradingDay::window($this->day);
    }

    /** "Wed 7 Oct". */
    public function dayLabel(?string $day = null): string
    {
        return CarbonImmutable::parse($day ?? $this->day, 'UTC')->format('D j M');
    }
}
