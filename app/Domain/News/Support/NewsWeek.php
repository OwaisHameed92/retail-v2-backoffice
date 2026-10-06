<?php

namespace App\Domain\News\Support;

use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;

/**
 * A news week (module 5.8): Monday to Sunday, London time, as wholesalers bill newsagents. Delivery dates are the
 * shop's calendar dates, so a week is a plain date range.
 */
final readonly class NewsWeek
{
    public function __construct(public string $start, public string $end) {}

    public static function current(): self
    {
        return self::containing(CarbonImmutable::now(Country::zone()));
    }

    /** The week of a `Y-m-d` date; the current week when blank or not a date, never later than the current week. */
    public static function from(mixed $date): self
    {
        $current = self::current();

        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $current;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, Country::zone());

        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            return $current;
        }

        $week = self::containing($parsed);

        return $week->start > $current->start ? $current : $week;
    }

    public static function containing(CarbonImmutable $day): self
    {
        $monday = $day->startOfWeek(CarbonImmutable::MONDAY);

        return new self($monday->format('Y-m-d'), $monday->addDays(6)->format('Y-m-d'));
    }

    public function shift(int $weeks): self
    {
        return self::containing(CarbonImmutable::createFromFormat('!Y-m-d', $this->start, Country::zone())->addWeeks($weeks));
    }

    /** @return array{start: string, end: string, previous: string, next: string|null, current: bool} */
    public function toArray(): array
    {
        $current = self::current();

        return [
            'start' => $this->start,
            'end' => $this->end,
            'previous' => $this->shift(-1)->start,
            'next' => $this->start < $current->start ? $this->shift(1)->start : null,
            'current' => $this->start === $current->start,
        ];
    }
}
