<?php

namespace App\Http\Controllers\App;

use App\Domain\Billing\Actions\SendBillingRequest;
use App\Domain\Billing\Data\PortalBilling;
use App\Domain\Billing\Enums\BillingRequestKind;
use App\Domain\Billing\GoCardless\Actions\FinishOwnerMandateSetup;
use App\Domain\Billing\GoCardless\Actions\StartOwnerMandateSetup;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\InvoicePdf;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\BillingRequestRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The tenant portal Billing page (module 1.13): plan, pricing, upfront payment, Direct Debit and invoices, and the
 * owner's own Direct Debit setup through the GoCardless hosted page. `company.can:billing.view` to read,
 * `billing.manage` (owner only) for the Direct Debit setup (route middleware); reachable while the business is
 * suspended, so the owner can set the Direct Debit up.
 */
class BillingController extends Controller
{
    public function index(CurrentCompany $tenancy): Response
    {
        return Inertia::render('app/billing', PortalBilling::for($tenancy->require(), canManage: $tenancy->can(Ability::BillingManage)));
    }

    public function startDirectDebit(CurrentCompany $tenancy, StartOwnerMandateSetup $start): SymfonyResponse
    {
        $url = $start->handle($tenancy->require(), route('app.billing.direct-debit.return'), route('app.billing'));

        return Inertia::location($url);
    }

    public function directDebitReturn(CurrentCompany $tenancy, FinishOwnerMandateSetup $finish): RedirectResponse
    {
        return $finish->handle($tenancy->require())
            ? redirect()->route('app.billing')->with('success', 'Your Direct Debit is set up. Thank you: nothing else to do.')
            : redirect()->route('app.billing')->with('success', 'Thank you. GoCardless is confirming your Direct Debit; this page updates within a few minutes.');
    }

    public function request(BillingRequestRequest $request, CurrentCompany $tenancy, SendBillingRequest $send): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $kind = $request->kind();
        $alert = $send->handle($tenancy->require(), $user, $kind, $request->validated('message'), $request->validated('phone'));

        return back()->with('success', ($alert->count ?? 1) > 1
            ? 'We already had this request and have reminded the team. They will be in touch soon.'
            : ($kind === BillingRequestKind::Cancel
                ? 'Request sent. The Switch & Save team will call you to arrange the cancellation, usually within one working day. Your tills keep working until then.'
                : 'Request sent. The Switch & Save team will be in touch about your new bank account, usually within one working day.'));
    }

    public function invoicePdf(string $invoice, InvoicePdf $pdf): HttpResponse
    {
        // Tenant scope: another company's invoice is simply not found.
        $model = Invoice::query()->where('status', '!=', 'draft')->findOrFail($invoice);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.InvoicePdf::filename($model).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
