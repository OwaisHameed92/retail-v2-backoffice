<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\Vat;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;

/**
 * The setup fee of a company: its override, else its plan's `setup_fee` (net, pounds), with VAT per the billing
 * settings. Instalments split the VAT-inclusive total into equal monthly parts (the last one takes the pennies
 * left over), so they always add up to the quoted gross.
 */
final class SetupFee
{
    /** The plan's fee (net). "0.00" without a plan. */
    public static function planFee(Company $company): string
    {
        return Money::normalise(DefaultPlan::for($company)->setup_fee ?? '0.00');
    }

    /** The fee this company pays (net): the override when set, else the plan's. */
    public static function net(Company $company, BillingAccount $account): string
    {
        return $account->setup_fee_override ?? self::planFee($company);
    }

    /**
     * One entry per instalment, net / VAT / gross.
     *
     * @return list<array{net: string, vat: string, gross: string}>
     */
    public static function schedule(Company $company, BillingAccount $account): array
    {
        $net = self::net($company, $account);

        if (Money::isZero($net)) {
            return [];
        }

        // Split what the customer was quoted (net + VAT on the whole fee) so the parts add up to it exactly;
        // each part's net and VAT are worked back from its gross.
        $parts = max(1, min((int) config('billing.direct_debit.max_instalments', 12), $account->setup_fee_instalments));
        $vatRate = Vat::rateFor($account);
        $gross = Money::add($net, InvoiceMaths::vatOn($net, $vatRate));
        $each = Money::round(bcdiv(Money::parse($gross), (string) $parts, 6));
        $schedule = [];
        $left = $gross;

        for ($i = 1; $i <= $parts; $i++) {
            $partGross = $i === $parts ? $left : $each;
            $left = Money::sub($left, $partGross);
            $split = InvoiceMaths::splitGross($partGross, $vatRate);
            $schedule[] = ['net' => $split['net'], 'vat' => $split['vat'], 'gross' => $partGross];
        }

        return $schedule;
    }

    /**
     * @return array{net: string, vat: string, gross: string}
     */
    public static function totals(Company $company, BillingAccount $account): array
    {
        $totals = InvoiceMaths::totals(self::schedule($company, $account));

        return ['net' => $totals['subtotal'], 'vat' => $totals['vat_total'], 'gross' => $totals['total']];
    }
}
