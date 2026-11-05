<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Actions\UndoUpfrontPayment;
use App\Domain\Billing\Actions\UpdateCompanyPricing;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Billing\PricingRequest;
use App\Http\Requests\Admin\Billing\UndoUpfrontPaymentRequest;
use App\Http\Requests\Admin\Billing\UpfrontPaymentRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The tenant Billing tab's pricing override and upfront payment (module 1.13), and undoing an upfront payment
 * recorded by mistake (owner 2026-11-05). `billing.manage` (route middleware).
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
        $payment = $request->toPayment();

        // Audit BILL-9: the same form again (a double click, a browser retry) records nothing more.
        if ($record->alreadyRecorded($company, $payment)) {
            return back()->with('success', 'This setup fee payment is already recorded. Nothing more was recorded.');
        }

        $record->handle($company, $payment);
        $state = SetupFeeState::for($company, app(BillingAccounts::class)->for($company));

        return back()->with('success', $state->isSettled()
            ? 'Setup fee payment recorded. The setup fee is paid.'
            : 'Setup fee payment recorded. '.BillingFormat::money($state->owed()).' still to pay.');
    }

    public function undoUpfront(UndoUpfrontPaymentRequest $request, Company $company, UndoUpfrontPayment $undo): RedirectResponse
    {
        $undo->handle($company, $request->reason());

        return back()->with('success', 'Upfront payment undone. The setup fee is not invoiced now: set the instalments in Payment settings, then Invoice setup fee. No email was sent.');
    }
}
