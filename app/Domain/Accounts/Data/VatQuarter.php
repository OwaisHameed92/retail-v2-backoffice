<?php

namespace App\Domain\Accounts\Data;

use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;

/**
 * A three-month VAT period (module 5.5), named by its first month (`quarter=2026-07` = July to September). HMRC
 * "stagger" groups: 1 ends March/June/September/December, 2 ends April/July/October/January, 3 ends
 * May/August/November/February. Default: the last period of stagger 1 that has ended.
 */
final readonly class VatQuarter
{
    public function __construct(public CarbonImmutable $start) {}

    public static function fromQuery(mixed $quarter, mixed $stagger = null): self
    {
        if (is_string($quarter) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $quarter, $m) === 1 && (int) $m[1] >= 2000 && (int) $m[1] <= 2100) {
            return new self(CarbonImmutable::create((int) $m[1], (int) $m[2], 1));
        }

        $s = is_string($stagger) && in_array($stagger, ['1', '2', '3'], true) ? (int) $stagger : 1;

        return self::latestEnded($s);
    }

    /** The newest period of a stagger that has ended by today (London). */
    public static function latestEnded(int $stagger): self
    {
        $month = TradingDay::today()->startOfMonth();

        // Walk back from last month until a month is a period end of this stagger.
        $end = $month->subMonth();

        while (self::staggerOfEndMonth($end->month) !== $stagger) {
            $end = $end->subMonth();
        }

        return new self($end->subMonths(2));
    }

    public function from(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function to(): string
    {
        return $this->start->addMonths(2)->endOfMonth()->format('Y-m-d');
    }

    public function key(): string
    {
        return $this->start->format('Y-m');
    }

    public function stagger(): int
    {
        return self::staggerOfEndMonth($this->start->addMonths(2)->month);
    }

    public function label(): string
    {
        return $this->start->format('M Y').' – '.$this->start->addMonths(2)->format('M Y');
    }

    /**
     * This stagger's periods, newest first: the one in progress, then the last seven.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        $latest = self::latestEnded($this->stagger())->start->addMonths(3);
        $out = [];

        for ($i = 0; $i < 8; $i++) {
            $q = new self($latest->subMonths(3 * $i));
            $out[] = ['value' => $q->key(), 'label' => $q->label()];
        }

        if (! in_array($this->key(), array_column($out, 'value'), true)) {
            $out[] = ['value' => $this->key(), 'label' => $this->label()];
        }

        return $out;
    }

    private static function staggerOfEndMonth(int $month): int
    {
        return match ($month % 3) {
            0 => 1,
            1 => 2,
            default => 3,
        };
    }
}
