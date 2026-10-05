<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Actions\UpdateCompanyPricing;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Billing\PricingRequest;
use App\Http\Requests\Admin\Billing\UpfrontPaymentRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The tenant Billing tab's pricing override and upfront payment (module 1.13). `billing.manage` (route middleware).
 */
class TenantPricingController extends Controller
{
    public function pricing(PricingRequest $request, Company $company, UpdateCompanyPricing $update): RedirectResponse
    {
        $update->handle($company, $request->toOverride());

        return back()->with('success', 'Pricing saved. New invoices and the Direct Debit amount follow it.');
    }

    public function upfront(UpfrontPaymentRequest $request, Company $company, RecordUpfrontPayment $record): RedirectResponse
    {
        $record->handle($company, $request->toPayment());
        $state = SetupFeeState::for($company, app(BillingAccounts::class)->for($company));

        return back()->with('success', $state->isSettled()
            ? 'Setup fee payment recorded. The setup fee is paid.'
            : 'Setup fee payment recorded. '.BillingFormat::money($state->owed()).' still to pay.');
    }
}
