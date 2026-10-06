<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Data\AuditFilters;
use App\Domain\Audit\Queries\AuditLogList;
use App\Domain\Audit\Queries\AuditSearch;
use App\Domain\Audit\Support\AuditCsv;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit log across every business (owner and support: `audit.view`). Read only; the CSV export is itself audited.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/audit-log/index', AuditLogList::for($request, AuditFilters::fromRequest($request, true), null));
    }

    public function export(Request $request, RecordAudit $audit): StreamedResponse
    {
        $filters = AuditFilters::fromRequest($request, true);
        $audit->handle('audit_log.exported', null, null, null, array_filter($filters->toArray()));

        return AuditCsv::download(AuditSearch::query($filters, null), false, 'audit-log-'.now(Country::zone())->format('Y-m-d-His').'.csv');
    }
}
