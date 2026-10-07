<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Billing\GoCardless\Actions\ChangeSubscription;
use App\Domain\Billing\GoCardless\Actions\ChargeSetupFee;
use App\Domain\Billing\GoCardless\Actions\RetryDirectDebitPayment;
use App\Domain\Billing\GoCardless\Actions\SendMandateSetupEmail;
use App\Domain\Billing\GoCardless\Actions\SyncSubscription;
use App\Domain\Billing\GoCardless\Actions\UpdateDirectDebitSettings;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Mail\Support\EmailControl;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Billing\DirectDebitSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Direct Debit on the tenant Billing tab (module 1.12): how the business pays, the setup email, the setup fee
 * and the GoCardless subscription. `billing.manage` (route middleware).
 */
class DirectDebitController extends Controller
{
    public function settings(DirectDebitSettingsRequest $request, Company $company, UpdateDirectDebitSettings $update): RedirectResponse
    {
        $update->handle($company, $request->toInput());

        return back()->with('success', 'Payment settings saved.');
    }

    public function sendSetup(Company $company, SendMandateSetupEmail $send): RedirectResponse
    {
        $sent = EmailControl::manually(fn () => $send->handle($company)); // P11: an admin's own send, never held

        if ($company->is_demo) {
            return back()->with('success', 'Demo business: the setup email is logged under Emails as "Not sent (demo)". Nothing was sent.');
        }

        return back()->with('success', 'Direct Debit setup email sent to '.($sent === 1 ? 'the owner.' : "{$sent} owners."));
    }

    public function retryPayment(Company $company, string $payment, RetryDirectDebitPayment $retry): RedirectResponse
    {
        $row = $retry->handle($company, $payment);

        return back()->with('success', 'GoCardless will collect '.BillingFormat::money($row->amount).' again'.($row->charge_date !== null ? ' on '.$row->charge_date->format('j M Y') : '').'.');
    }

    public function chargeSetupFee(Company $company, ChargeSetupFee $charge): RedirectResponse
    {
        $invoices = $charge->handle($company);
        $total = BillingFormat::money(Money::sum(array_map(fn ($invoice) => $invoice->total, $invoices)));

        return back()->with('success', count($invoices) === 1
            ? "Setup fee of {$total} invoiced as {$invoices[0]->number}."
            : 'Setup fee of '.$total.' invoiced in '.count($invoices).' instalments.');
    }

    public function syncSubscription(Company $company, SyncSubscription $sync): RedirectResponse
    {
        try {
            $outcome = $sync->handle($company, 'staff');
        } catch (GoCardlessException $exception) {
            throw ValidationException::withMessages(['subscription' => $exception->getMessage()]);
        }

        return back()->with('success', match ($outcome) {
            'demo' => 'Demo business: nothing is sent to GoCardless.',
            'noMandate' => 'Nothing to update: the Direct Debit is not set up yet.',
            'nothingToCollect' => 'Nothing to collect: there are no live tills.',
            'unchanged' => 'The subscription already matches the live tills.',
            'created' => 'Direct Debit subscription created.',
            'cancelled' => 'No live tills, so the subscription was cancelled.',
            default => 'Subscription updated from the next payment.',
        });
    }

    public function subscription(Company $company, string $action, ChangeSubscription $change): RedirectResponse
    {
        /** @var 'pause'|'resume'|'cancel' $action */
        $status = $change->handle($company, $action);

        return back()->with('success', 'Subscription '.mb_strtolower($status->label()).'.');
    }
}
