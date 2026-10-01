<?php

namespace App\Domain\Purchasing\Reorder;

/**
 * One suggestion (module 6.4): the cases to order and the figures and plain-English reasons behind them.
 * `method`: `forecast` (from sales), `levels` (no sales yet: the min/max rule of 5.2) or `none` (nothing to go on).
 */
final readonly class ReorderResult
{
    /**
     * @param  list<string>  $flags  outOfStock, negativeStock, runsOut, shortLife, wasteRisk, seasonal, spike, slowing, overstock, noHistory, capped
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $cases,
        public string $units,
        public string $rate,
        public ?string $coverDays,
        public string $forecast,
        public string $position,
        public string $safety,
        public string $method,
        public array $flags,
        public array $reasons,
    ) {}

    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}
