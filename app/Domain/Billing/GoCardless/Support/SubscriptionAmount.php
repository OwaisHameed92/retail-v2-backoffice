<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\Billing\Support\InvoiceLineBuilder;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\Vat;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * What the company owes each cycle, and so what the Direct Debit subscription collects: exactly the invoice of a
 * full period (one line per live till or per active branch at the unit price for the cycle, VAT per the billing
 * settings), so every GoCardless payment matches the invoice we raise for it. £0 = nothing recurring to collect.
 */
final class SubscriptionAmount
{
    /**
     * @return array{net: string, vat: string, gross: string, tills: int, units: int, mode: PricingMode, unitPrice: string|null, cycle: BillingCycle, start: CarbonImmutable}
     */
    public static function for(Company $company, BillingAccount $account, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $licences = RenewCompanyLicences::renewable($company)->get();
        $pricing = CompanyPricing::for($company, $account, $licences);
        $start = BillingPeriod::nextStart($company, $now, $licences);
        $cycle = $account->cycle;
        $lines = InvoiceLineBuilder::build($licences, $start, $cycle->periodEnd($start), $cycle, Vat::rateFor($account), false, $pricing);
        $totals = InvoiceMaths::totals($lines);
        $prices = array_values(array_unique(array_column($lines, 'unit_price')));

        return [
            'net' => $totals['subtotal'],
            'vat' => $totals['vat_total'],
            'gross' => $totals['total'],
            'tills' => $licences->count(),
            'units' => count($lines),
            'mode' => $pricing->mode,
            'unitPrice' => count($prices) === 1 ? $prices[0] : ($prices === [] ? $pricing->unitPrice($cycle) : null),
            'cycle' => $cycle,
            'start' => $start,
        ];
    }
}
