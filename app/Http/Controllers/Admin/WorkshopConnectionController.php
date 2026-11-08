<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Workshop\Actions\ConnectWorkshop;
use App\Domain\Workshop\Actions\DisconnectWorkshop;
use App\Domain\Workshop\Actions\IssueWorkshopApiKey;
use App\Domain\Workshop\Actions\ReplayWorkshopWebhook;
use App\Domain\Workshop\Actions\SetWorkshopAddOn;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use App\Domain\Workshop\Queries\WorkshopCard;
use App\Domain\Workshop\Queries\WorkshopLog;
use App\Domain\Workshop\Support\ConnectorSecret;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WorkshopAddOnRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * W5: a shop's "Workshop integration (BikeBook)" card on the admin tenant page and its sync log. 404 while
 * WORKSHOP_CONNECTOR is off (route middleware). Reading: `viewAny` (tenants.view); changes: `manage`
 * (licences.manage), both WorkshopConnectionPolicy. The shop is looked up inside its company only. A new API key
 * answers JSON once (`Cache-Control: no-store`), never a flash.
 */
class WorkshopConnectionController extends Controller
{
    public function addOn(WorkshopAddOnRequest $request, Company $company, string $branch, SetWorkshopAddOn $set): RedirectResponse
    {
        $shop = $set->handle(TenantBranchController::find($company, $branch), $request->boolean('on'), $request->user('admin'));

        return back()->with('success', $request->boolean('on')
            ? "Workshop integration added to {$shop->name}'s licence. Its tills get it at their next licence check."
            : "Workshop integration taken off {$shop->name}'s licence.");
    }

    public function connect(Request $request, Company $company, string $branch, ConnectWorkshop $connect): RedirectResponse
    {
        $shop = TenantBranchController::find($company, $branch);
        $connect->handle($shop, ConnectionStatus::Pending, $request->user('admin'));

        return back()->with('success', "{$shop->name} is ready for BikeBook. The owner approves it from BikeBook, or create an API key.");
    }

    public function disconnect(Request $request, Company $company, string $branch, DisconnectWorkshop $disconnect): RedirectResponse
    {
        $connection = $this->connection($company, $branch);
        $disconnect->handle($connection, $request->user('admin'));

        return back()->with('success', 'BikeBook is disconnected. Its calls are refused from now on.');
    }

    public function issueKey(Request $request, Company $company, string $branch, IssueWorkshopApiKey $issue): JsonResponse
    {
        $shop = TenantBranchController::find($company, $branch);
        $key = $issue->handle($shop, $request->user('admin'));

        return response()->json([
            'message' => "New BikeBook API key for {$shop->name}. Any older key stopped working.",
            'key' => $key,
            'last4' => ConnectorSecret::last4($key),
            'branchName' => $shop->name,
        ])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function revokeKey(Request $request, Company $company, string $branch, IssueWorkshopApiKey $issue): RedirectResponse
    {
        $issue->revoke($this->connection($company, $branch), $request->user('admin'));

        return back()->with('success', 'The BikeBook API key is revoked.');
    }

    public function log(Request $request, Company $company, string $branch, CurrentCompany $tenancy): Response
    {
        $shop = TenantBranchController::find($company, $branch);
        $connection = $this->connection($company, $branch);
        $tab = $request->query('tab') === 'deliveries' ? 'deliveries' : 'calls';

        return Inertia::render('admin/tenants/workshop-log', [
            'tenant' => TenantData::company($company),
            'card' => $tenancy->runAs($company, fn () => WorkshopCard::forBranch($shop)),
            'tab' => $tab,
            'rows' => $tab === 'deliveries' ? WorkshopLog::deliveries($request, $connection) : WorkshopLog::calls($request, $connection),
            'canManage' => $request->user('admin')?->can('manage', WorkshopConnection::class) ?? false,
        ]);
    }

    public function export(Request $request, Company $company, string $branch): StreamedResponse
    {
        return WorkshopLog::csv($this->connection($company, $branch), $request->query('kind') === 'deliveries' ? 'deliveries' : 'calls');
    }

    public function replay(Request $request, Company $company, string $branch, string $delivery, ReplayWorkshopWebhook $replay): RedirectResponse
    {
        $connection = $this->connection($company, $branch);
        $row = WorkshopWebhookDelivery::withoutCompanyScope()->where('connection_id', $connection->id)->findOrFail($delivery);
        $replay->handle($row, $request->user('admin'));

        return back()->with('success', 'The webhook is being sent to BikeBook again.');
    }

    private function connection(Company $company, string $branch): WorkshopConnection
    {
        $shop = TenantBranchController::find($company, $branch);

        return WorkshopConnection::withoutCompanyScope()->where('company_id', $company->id)->where('branch_id', $shop->id)->firstOrFail();
    }
}
