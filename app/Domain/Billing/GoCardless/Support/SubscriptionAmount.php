<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Billing\Support\InvoiceLineBuilder;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\Vat;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * What the Direct Debit subscription collects each cycle: exactly the invoice of a full period (one line per live
 * licence of an active till at its plan's monthly or yearly price, VAT per the billing settings), so every
 * GoCardless payment matches the invoice we raise for it.
 */
final class SubscriptionAmount
{
    /**
     * @return array{net: string, vat: string, gross: string, tills: int, cycle: BillingCycle, start: CarbonImmutable}
     */
    public static function for(Company $company, BillingAccount $account, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $licences = RenewCompanyLicences::renewable($company)->get();
        $start = BillingPeriod::nextStart($company, $now, $licences);
        $cycle = $account->cycle;
        $lines = InvoiceLineBuilder::build($licences, $start, $cycle->periodEnd($start), $cycle, Vat::rateFor($account), false);
        $totals = InvoiceMaths::totals($lines);

        return [
            'net' => $totals['subtotal'],
            'vat' => $totals['vat_total'],
            'gross' => $totals['total'],
            'tills' => count($lines),
            'cycle' => $cycle,
            'start' => $start,
        ];
    }
}
