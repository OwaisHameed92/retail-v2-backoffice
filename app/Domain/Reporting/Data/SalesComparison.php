<?php

namespace App\Domain\Reporting\Data;

use App\Domain\Shared\Support\Money;

/**
 * Current figures against a compare window (DASHBOARD.md §2.9): change = (current − previous) ÷ previous, as a
 * percentage with one decimal; null ("—") when the previous figure is 0.
 */
final readonly class SalesComparison
{
    public function __construct(public SalesTotals $current, public SalesTotals $previous) {}

    /**
     * @param  'gross'|'net'|'vat'|'takings'|'transactions'|'refundGross'|'discount'  $field
     */
    public function changePercent(string $field): ?string
    {
        $now = (string) $this->current->{$field};
        $before = (string) $this->previous->{$field};

        if (Money::isZero($before)) {
            return null;
        }

        return Money::round(bcdiv(bcmul(bcsub(Money::parse($now), Money::parse($before), 12), '100', 12), Money::parse($before), 12), 1);
    }
}
