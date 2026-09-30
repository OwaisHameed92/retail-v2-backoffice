<?php

namespace App\Domain\Customers\Queries;

use App\Domain\Billing\Support\BillingDates;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * A customer's account statement for a date range (module 4.4): opening balance and points (the ledger before the
 * first day), every ledger row of the range from every shop with the portal's running balance, totals and the closing
 * figures. Days are London calendar days; the ledger is the truth (contract §10.1). The same array feeds the screen,
 * the PDF and the email. Runs in the company scope.
 */
final class CustomerStatement
{
    /** Longest range one statement covers. */
    public const MAX_DAYS = 731;

    /**
     * The range asked for; left out, the month of `to` (default today) up to `to`. Dates are "Y-m-d" London days.
     *
     * @return array{0: string, 1: string}
     */
    public static function period(?string $from, ?string $to): array
    {
        $to = $to ?: BillingDates::today()->format('Y-m-d');

        return [$from ?: BillingDates::date($to)->startOfMonth()->format('Y-m-d'), $to];
    }

    /** @return array<string, mixed> */
    public static function for(Company $company, Customer $customer, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from.' 00:00:00', BillingDates::TIMEZONE);
        $end = CarbonImmutable::parse($to.' 00:00:00', BillingDates::TIMEZONE)->addDay();
        $branches = CustomerDetail::branches();
        $opening = CustomerLedger::totals($customer->id, $start);
        $running = $opening;
        $rows = [];
        $charges = $credits = '0.00';
        $earned = $used = 0;

        foreach (CustomerLedger::between($customer->id, $start, $end) as $row) {
            $amount = Money::normalise($row->amount ?? '0');
            $points = (int) $row->points;
            $running = ['balance' => Money::add($running['balance'], $amount), 'points' => $running['points'] + $points];
            $rows[] = CustomerLedger::present($row, $branches, $running);

            if (Money::isNegative($amount)) {
                $credits = Money::sub($credits, $amount);
            } else {
                $charges = Money::add($charges, $amount);
            }

            $used += max(0, -$points);
            $earned += max(0, $points);
        }

        return [
            'business' => [
                'name' => $company->name,
                'address' => implode(', ', array_filter([$company->address, $company->town, $company->postcode])),
                'phone' => $company->phone,
                'email' => $company->email,
                'vatNumber' => $company->vat_number,
            ],
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name ?: 'Customer',
                'address' => $customer->address ?: null,
                'email' => $customer->email ?: null,
                'cardNo' => $customer->card_no ?: null,
            ],
            'from' => $from,
            'to' => $to,
            'period' => BillingDates::range(BillingDates::date($from), BillingDates::date($to)),
            'issued' => BillingDates::long(BillingDates::today()),
            'opening' => $opening,
            'rows' => $rows,
            'totals' => ['charges' => $charges, 'credits' => $credits, 'pointsEarned' => $earned, 'pointsUsed' => $used],
            'closing' => $running,
        ];
    }

    /** "statement-lc000123-2026-10-01-to-2026-10-31.pdf" */
    public static function filename(Customer $customer, string $from, string $to): string
    {
        $who = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $customer->card_no ?: $customer->id));

        return "statement-{$who}-{$from}-to-{$to}.pdf";
    }
}
