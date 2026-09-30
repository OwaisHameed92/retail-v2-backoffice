<?php

namespace App\Http\Controllers\App;

use App\Domain\Setup\Actions\DeleteSetupRow;
use App\Domain\Setup\Actions\SavePaymentType;
use App\Domain\Setup\Actions\SaveReason;
use App\Domain\Setup\Queries\TenderAndReasonLists;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Reason;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use App\Http\Requests\App\Setup\PaymentTypeRequest;
use App\Http\Requests\App\Setup\ReasonRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The till's payment types and reason codes on the tenant portal (module 4.5, `company.can:settings.manage`),
 * edited in dialogs on their list. Every change reaches every till at its next sync.
 */
class TillListController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function paymentTypes(Request $request): Response
    {
        return Inertia::render('app/till-lists/payment-types', TenderAndReasonLists::paymentTypes($request));
    }

    public function storePaymentType(PaymentTypeRequest $request, SavePaymentType $save): RedirectResponse
    {
        $type = $save->handle($this->tenancy->require(), null, $request->validated());

        return back()->with('success', "{$type->name} added. Your tills get it at their next sync.");
    }

    public function updatePaymentType(PaymentTypeRequest $request, string $paymentType, SavePaymentType $save): RedirectResponse
    {
        $type = $save->handle($this->tenancy->require(), PaymentType::query()->findOrFail($paymentType)->id, $request->validated());

        return back()->with('success', "{$type->name} saved.");
    }

    public function destroyPaymentType(CompanyWideWriteRequest $request, string $paymentType, DeleteSetupRow $delete): RedirectResponse
    {
        $type = PaymentType::query()->findOrFail($paymentType);
        $delete->handle($this->tenancy->require(), PaymentType::class, $type->id);

        return back()->with('success', "{$type->name} removed from the tills.");
    }

    public function reasons(Request $request): Response
    {
        return Inertia::render('app/till-lists/reasons', TenderAndReasonLists::reasons($request));
    }

    public function storeReason(ReasonRequest $request, SaveReason $save): RedirectResponse
    {
        $save->handle($this->tenancy->require(), null, $request->validated());

        return back()->with('success', 'Reason added. Your tills get it at their next sync.');
    }

    public function updateReason(ReasonRequest $request, string $reason, SaveReason $save): RedirectResponse
    {
        $save->handle($this->tenancy->require(), Reason::query()->findOrFail($reason)->id, $request->validated());

        return back()->with('success', 'Reason saved.');
    }

    public function destroyReason(CompanyWideWriteRequest $request, string $reason, DeleteSetupRow $delete): RedirectResponse
    {
        $delete->handle($this->tenancy->require(), Reason::class, Reason::query()->findOrFail($reason)->id);

        return back()->with('success', 'Reason removed from the tills.');
    }
}
