<?php

namespace App\Domain\Reporting\Data;

use App\Domain\Shared\Support\Money;

/**
 * The KPI tiles of DASHBOARD.md §2.2 for a scope. Money as fixed-scale strings (pounds), never floats. Refund
 * values are shown positive; every other figure is already net of refunds. `grossProfit` is null when no line had a
 * cost (§1.2: most shops enter none — hide the tile). Averages are null when there were no transactions ("—").
 */
final readonly class SalesTotals
{
    public function __construct(
        public string $gross,
        public string $net,
        public string $vat,
        public int $transactions,
        public int $refundCount,
        public string $refundGross,
        public string $refundNet,
        public string $discount,
        public string $promo,
        public string $coupon,
        public string $cost,
        public string $containerDeposits,
        public string $takings,
        public int $voidCount,
        public string $voidTotal,
    ) {}

    /**
     * @param  array<string, string|int>  $sums  rpt_sales_daily column => sum
     */
    public static function fromSums(array $sums): self
    {
        return new self(
            (string) $sums['gross'], (string) $sums['net'], (string) $sums['vat'], (int) $sums['txn_count'],
            (int) $sums['refund_count'], (string) $sums['refund_gross'], (string) $sums['refund_net'],
            (string) $sums['discount'], (string) $sums['promo'], (string) $sums['coupon'], (string) $sums['cost'],
            (string) $sums['container_deposits'], (string) $sums['takings'], (int) $sums['void_count'], (string) $sums['void_total'],
        );
    }

    /** Net sales ÷ transactions (the till's "average basket", ex VAT). */
    public function averageBasketExVat(): ?string
    {
        return self::average($this->net, $this->transactions);
    }

    public function averageBasketIncVat(): ?string
    {
        return self::average($this->gross, $this->transactions);
    }

    /** Manual and staff discounts: line discount minus the promotion and coupon shares. */
    public function manualDiscount(): string
    {
        return Money::sub(Money::sub($this->discount, $this->promo), $this->coupon);
    }

    public function grossProfit(): ?string
    {
        return Money::isZero($this->cost) ? null : Money::sub($this->net, $this->cost);
    }

    public static function average(string $amount, int $count): ?string
    {
        return $count > 0 ? Money::round(bcdiv(Money::parse($amount), (string) $count, 12)) : null;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            ...get_object_vars($this),
            'averageBasketExVat' => $this->averageBasketExVat(),
            'averageBasketIncVat' => $this->averageBasketIncVat(),
            'manualDiscount' => $this->manualDiscount(),
            'grossProfit' => $this->grossProfit(),
        ];
    }
}
