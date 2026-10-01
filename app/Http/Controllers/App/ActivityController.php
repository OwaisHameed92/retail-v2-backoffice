<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Data\AuditFilters;
use App\Domain\Audit\Queries\AuditLogList;
use App\Domain\Audit\Queries\AuditSearch;
use App\Domain\Audit\Support\AuditCsv;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The business's own activity log (`audit.view`: owner by default). Always pinned to the current company.
 */
class ActivityController extends Controller
{
    public function index(Request $request, CurrentCompany $tenancy): Response
    {
        $companyId = $tenancy->require()->id;

        return Inertia::render('app/activity/index', AuditLogList::for($request, AuditFilters::fromRequest($request, false), $companyId));
    }

    public function export(Request $request, CurrentCompany $tenancy, RecordAudit $audit): StreamedResponse
    {
        $companyId = $tenancy->require()->id;
        $filters = AuditFilters::fromRequest($request, false);
        $audit->handle('audit_log.exported', null, null, null, array_filter($filters->toArray()), companyId: $companyId);

        return AuditCsv::download(AuditSearch::query($filters, $companyId), true, 'activity-'.now('Europe/London')->format('Y-m-d-His').'.csv');
    }
}
