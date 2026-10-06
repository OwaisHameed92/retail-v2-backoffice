<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Licensing\Enums\RenewalPeriod;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A renewal: +1 month, +1 year (from the current paid/trial end when that is still ahead, else from now), or
 * until a chosen date. Expiry is always the end of that day in the shops' time zone, stored in UTC.
 */
final readonly class RenewalTerm
{
    private function __construct(
        public RenewalPeriod $period,
        public ?CarbonImmutable $until = null,
    ) {}

    public static function month(): self
    {
        return new self(RenewalPeriod::Month);
    }

    public static function year(): self
    {
        return new self(RenewalPeriod::Year);
    }

    /** Until the end of this calendar day (shop time zone). */
    public static function until(CarbonInterface $date): self
    {
        return new self(RenewalPeriod::Until, CarbonImmutable::instance($date));
    }

    public static function from(RenewalPeriod $period, ?CarbonInterface $until = null): self
    {
        if ($period === RenewalPeriod::Until) {
            return self::until($until ?? throw new InvalidArgumentException('A renewal until a date needs the date.'));
        }

        return new self($period);
    }

    /**
     * The new expiry. `$currentEnd` is the licence's current end (paid expiry, else trial end); relative terms
     * extend it when it is still in the future so no paid or trial days are lost.
     */
    public function expiryFrom(?CarbonImmutable $currentEnd, CarbonImmutable $now): CarbonImmutable
    {
        if ($this->period === RenewalPeriod::Until) {
            /** @var CarbonImmutable $until */
            $until = $this->until;

            return self::endOfLocalDay($until->setTimezone(Country::zone())->format('Y-m-d'));
        }

        $base = ($currentEnd !== null && $currentEnd->greaterThan($now) ? $currentEnd : $now)->setTimezone(Country::zone());
        $next = $this->period === RenewalPeriod::Month ? $base->addMonthNoOverflow() : $base->addYearNoOverflow();

        return self::endOfLocalDay($next->format('Y-m-d'));
    }

    /** 23:59:59 on that London date, as UTC. */
    public static function endOfLocalDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' 23:59:59', Country::zone())->utc();
    }
}
