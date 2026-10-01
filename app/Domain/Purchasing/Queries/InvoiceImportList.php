<?php

namespace App\Domain\Purchasing\Queries;

use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Invoices\InvoiceImportAccess;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Props for the invoice import screen (module 6.5): whether the plan has it and whether the model can read now (else
 * entering by hand), the shops this user may import for, the upload rules, and the imports so far (one-shop users:
 * their shop's), searchable by number or file name and filterable by status.
 */
final class InvoiceImportList
{
    public const MAX_KB = 10240;

    /** @return array<string, mixed> */
    public static function for(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $tenancy = app(CurrentCompany::class);
        $company = $tenancy->require();
        $restricted = $tenancy->restrictedBranchId();
        $status = InvoiceImportStatus::tryFrom((string) $request->query('status', ''));
        $inPlan = InvoiceImportAccess::inPlan($company);

        $query = InvoiceImportAccess::visible()->with(['branch' => fn ($q) => $q->withTrashed(), 'user:id,name'])
            ->when($status !== null, fn ($q) => $q->where('status', $status->value));
        $suppliers = Supplier::query()->withTrashed()->pluck('name', 'id');

        return [
            'access' => [
                'inPlan' => $inPlan,
                'reader' => $inPlan ? InvoiceImportAccess::reader($user, $company) : ['available' => false, 'message' => null],
                'oneShop' => $restricted !== null,
            ],
            'shops' => Branch::query()->where('is_active', true)->when($restricted !== null, fn ($q) => $q->whereKey($restricted))
                ->orderBy('name')->get(['id', 'name', 'code'])->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->values()->all(),
            'limits' => ['maxKb' => self::MAX_KB, 'types' => ['PDF', 'JPG', 'PNG'], 'retentionDays' => InvoiceImport::FILE_RETENTION_DAYS],
            'filters' => ['status' => $status?->value],
            'statuses' => array_map(fn (InvoiceImportStatus $s) => ['value' => $s->value, 'label' => $s->label()], InvoiceImportStatus::cases()),
            'imports' => TableQuery::from($request)->searchable(['invoice_number', 'file_name'])->sortable(['created_at', 'invoice_date', 'gross_total'])
                ->defaultSort('created_at', 'desc')->defaultPerPage(25)
                ->paginate($query, fn (InvoiceImport $i) => [
                    'id' => $i->id, 'status' => $i->status->value, 'statusLabel' => $i->status->label(), 'method' => $i->method,
                    'shop' => $i->branch->name ?? null, 'supplier' => $i->supplier_id !== null ? ($suppliers[$i->supplier_id] ?? null) : null,
                    'supplierName' => $i->draft['supplierName'] ?? null, 'invoiceNumber' => $i->invoice_number,
                    'invoiceDate' => $i->invoice_date?->format('Y-m-d'), 'gross' => $i->gross_total, 'fileName' => $i->file_name,
                    'uploadedBy' => $i->user->name ?? null, 'createdAt' => $i->created_at?->toIso8601ZuluString(),
                    'lines' => count($i->draft['lines'] ?? []),
                ]),
        ];
    }
}
