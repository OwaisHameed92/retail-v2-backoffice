<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\EmailInvoice;
use App\Domain\Billing\Data\InvoiceData;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Queries\InvoiceQuery;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\InvoicePdf;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Invoice list, detail and PDF (module 1.8). Reading needs `tenants.view`; changes are in InvoiceActionController.
 */
class InvoiceController extends Controller
{
    use FindsBillingRecords;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(InvoiceQuery::STATUS_FILTERS)],
            'company' => ['nullable', 'string', 'max:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $now = CarbonImmutable::now();
        $table = TableQuery::from($request)->sortable(InvoiceQuery::SORTABLE)->defaultSort('invoices.created_at', 'desc');
        $query = InvoiceQuery::admin($filters, $table->search());
        $totals = InvoiceQuery::totals($query);
        $company = isset($filters['company']) ? Company::query()->withTrashed()->find($filters['company']) : null;

        return Inertia::render('admin/billing/invoices/index', [
            'invoices' => $table->paginate($query, fn (Invoice $invoice) => InvoiceData::row($invoice, $now)),
            'filters' => [
                'status' => $filters['status'] ?? null,
                'company' => $company === null ? null : ['id' => $company->id, 'name' => $company->name],
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'totals' => [
                'count' => $totals['count'],
                'total' => BillingFormat::money($totals['total']),
                'balance' => BillingFormat::money($totals['balance']),
            ],
            'statuses' => InvoiceStatus::options(),
            'counts' => InvoiceQuery::countsByStatus(),
            'canManage' => $request->user('admin')?->hasAbility(AdminRole::BILLING_MANAGE) ?? false,
        ]);
    }

    public function show(Request $request, string $invoice): Response
    {
        $model = $this->findInvoice($invoice);
        $canManage = $request->user('admin')?->hasAbility(AdminRole::BILLING_MANAGE) ?? false;

        return Inertia::render('admin/billing/invoices/show', [
            'invoice' => InvoiceData::detail($model, $canManage, CarbonImmutable::now()),
            'activity' => InvoiceData::activity($model),
            // P11: emails of this invoice held because invoices are not sent automatically.
            'heldEmails' => count(EmailInvoice::held($model)),
        ]);
    }

    public function pdf(Request $request, string $invoice, InvoicePdf $pdf): HttpResponse
    {
        $model = $this->findInvoice($invoice);
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.InvoicePdf::filename($model).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
