<?php

namespace App\Http\Controllers\App;

use App\Domain\Privacy\Actions\AnonymiseCustomer;
use App\Domain\Privacy\Actions\ApplyRetention;
use App\Domain\Privacy\Actions\CompleteTillSteps;
use App\Domain\Privacy\Actions\ExportCustomerData;
use App\Domain\Privacy\Actions\SavePrivacySettings;
use App\Domain\Privacy\Enums\DataRequestStatus;
use App\Domain\Privacy\Queries\PrivacyPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Privacy\AnonymiseCustomerRequest;
use App\Http\Requests\App\Privacy\PrivacySettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Privacy (module 7.7, UK GDPR), owner only (`privacy.manage`): customer data requests, a customer's data export
 * (ZIP of JSON, PDF and CSVs, streamed and never kept), erasure (anonymise), data retention and its "anonymise now".
 * Everything is audited and runs in the current company's scope: another business's customer is simply not found.
 */
class PrivacyController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        return Inertia::render('app/privacy/index', PrivacyPage::for($request));
    }

    public function updateSettings(PrivacySettingsRequest $request, SavePrivacySettings $save): RedirectResponse
    {
        $save->handle($this->tenancy->require(), $request->months(), $request->boolean('auto_anonymise'), $request->user()?->id);

        return back()->with('success', 'Data retention saved. Your tills get the period at their next sync.');
    }

    public function applyRetention(Request $request, ApplyRetention $retention): RedirectResponse
    {
        $outcome = $retention->handle($this->tenancy->require(), true, $request->user()?->id);
        $message = $outcome['anonymised'] === 1 ? '1 customer anonymised.' : "{$outcome['anonymised']} customers anonymised.";

        if ($outcome['skipped'] > 0) {
            $message .= " {$outcome['skipped']} skipped: their account is not settled.";
        }

        return back()->with('success', $message);
    }

    public function export(Request $request, string $customer, ExportCustomerData $export): BinaryFileResponse
    {
        $result = $export->handle($this->tenancy->require(), $customer, $request->user()?->id);

        return response()->download($result['path'], $result['filename'], [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }

    public function anonymise(AnonymiseCustomerRequest $request, string $customer, AnonymiseCustomer $anonymise): RedirectResponse
    {
        $result = $anonymise->handle($this->tenancy->require(), $customer, $request->user()?->id, 'owner', $request->validated('note'));

        return back()->with('success', $result->status === DataRequestStatus::TillPending
            ? 'Customer anonymised. Some records on the tills still need clearing: see the steps on the request.'
            : 'Customer anonymised. Your tills get the change at their next sync.');
    }

    public function tillDone(Request $request, string $dataRequest, CompleteTillSteps $complete): RedirectResponse
    {
        $complete->handle($this->tenancy->require(), $dataRequest, $request->user()?->id);

        return back()->with('success', 'Request marked as completed.');
    }
}
