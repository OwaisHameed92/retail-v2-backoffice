<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\GoCardless\Support\SetupFeeByDirectDebit;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;

/**
 * Owner 2026-11-11 (Asia Halal Food Stores): a setup fee paid in 2 or more instalments may start with a deposit
 * (`billing_accounts.setup_fee_deposit`, net pounds): part 1 is the deposit, the rest of the VAT-inclusive total is split
 * equally across the other parts (SetupFee::equalParts, the last part takes the pennies). Null = equal parts, as
 * before. A deposit that no longer fits the fee (the plan's fee changed, the instalments went to 1) is not used: the
 * fee is then split equally, so nothing is ever invoiced as a £0 or negative part.
 */
final class SetupFeeDeposit
{
    /**
     * Why `$deposit` (net) cannot start a setup fee of `$net` in `$instalments` parts; null when it can (or there is
     * no deposit).
     */
    public static function problem(string $net, ?string $deposit, int $instalments, string $vatRate): ?string
    {
        if ($deposit === null) {
            return null;
        }

        return match (true) {
            Money::compare($deposit, '0') <= 0 => 'The deposit must be more than '.MoneyFormat::format('0').'.',
            $instalments < 2 => 'A deposit needs 2 or more instalments: the deposit first, then the rest.',
            Money::compare($net, '0') <= 0 => 'There is no setup fee to take a deposit from.',
            Money::compare($deposit, $net) >= 0 => 'The deposit must be less than the setup fee ('.MoneyFormat::format($net).' before '.Country::tax('VAT').').',
            ! self::restSplits($net, $deposit, $instalments, $vatRate) => 'The deposit leaves too little to split into '.($instalments - 1).' more instalments.',
            default => null,
        };
    }

    /** The deposit is set and fits the fee: part 1 is the deposit. */
    public static function applies(string $net, ?string $deposit, int $instalments, string $vatRate): bool
    {
        return $deposit !== null && self::problem($net, $deposit, $instalments, $vatRate) === null;
    }

    /**
     * How the setup fee is split, when it starts with a deposit: "£420.00 deposit, then 11 × £81.60" (VAT inclusive),
     * from the setup fee invoices once invoiced, else from the planned parts. Null without a deposit.
     */
    public static function text(Company $company, BillingAccount $account): ?string
    {
        if ($account->setup_fee_deposit === null) {
            return null;
        }

        $invoices = SetupFeeByDirectDebit::invoices($company);
        $grosses = $invoices->isNotEmpty()
            ? $invoices->map(fn (Invoice $invoice) => (string) $invoice->total)->values()->all()
            : ($account->setup_fee_invoiced_at === null ? array_column(SetupFee::schedule($company, $account), 'gross') : []);

        return count($grosses) > 1 && self::applies(SetupFee::net($company, $account), $account->setup_fee_deposit, SetupFee::parts($account), Vat::rateFor($account))
            ? self::describe($grosses)
            : null;
    }

    /**
     * "£420.00 deposit, then 11 × £81.60"; the last part's pennies: "£400.00 deposit, then 11 × £72.73 (the last
     * £72.70)"; two parts: "£420.00 deposit, then £897.60".
     *
     * @param  list<string>  $grosses  part 1 (the deposit) first
     */
    public static function describe(array $grosses): string
    {
        $deposit = BillingFormat::money(array_shift($grosses)).' deposit';
        $count = count($grosses);

        if ($count === 1) {
            return $deposit.', then '.BillingFormat::money($grosses[0]);
        }

        $last = $grosses[$count - 1];
        $each = $grosses[0];

        return $deposit.", then {$count} × ".BillingFormat::money($each)
            .(Money::equals($last, $each) ? '' : ' (the last '.BillingFormat::money($last).')');
    }

    /** Every part after the deposit is more than £0. */
    private static function restSplits(string $net, string $deposit, int $instalments, string $vatRate): bool
    {
        $gross = Money::add($net, InvoiceMaths::vatOn($net, $vatRate));
        $rest = Money::sub($gross, Money::add($deposit, InvoiceMaths::vatOn($deposit, $vatRate)));

        foreach (SetupFee::equalParts($rest, $instalments - 1, $vatRate) as $part) {
            if (Money::compare($part['gross'], '0') <= 0) {
                return false;
            }
        }

        return true;
    }
}
