<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\URL;

/**
 * Our signed links for the customer: "set up Direct Debit" (opens a fresh GoCardless page every time, so the
 * email link keeps working after a GoCardless page expires) and the page GoCardless sends them back to.
 */
final class SetupLink
{
    public static function for(Company $company): string
    {
        return URL::temporarySignedRoute('direct-debit.setup', now()->addDays(max(1, (int) config('billing.direct_debit.setup_link_days', 14))), ['company' => $company->id]);
    }

    public static function done(Company $company): string
    {
        return URL::temporarySignedRoute('direct-debit.done', now()->addDays(2), ['company' => $company->id]);
    }
}
