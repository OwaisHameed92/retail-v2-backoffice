<?php

namespace App\Domain\Purchasing\Queries;

use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Invoices\InvoiceAnalysis;
use App\Domain\Purchasing\Invoices\InvoiceDocuments;
use App\Domain\Purchasing\Invoices\InvoiceImportAccess;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Purchasing\Support\HeadOfficeOrders;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\GoodsReceiptStatus;
use App\Domain\TillData\Models\GoodsReceipt;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\Supplier;
use App\Domain\TillData\Models\VatRate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Props for reviewing one invoice import (module 6.5): the import and its file, the draft as the user edits it, the
 * deterministic analysis (each line's product, sums, order or delivery figures; the checks; the cost price preview),
 * the supplier, order and delivery choices, a product search for re-pointing a line, and what this user may do.
 */
final class InvoiceReviewPage
{
    /** @return array<string, mixed> */
    public static function for(Request $request, InvoiceImport $import): array
    {
        $draft = $import->draft;
        $shop = Branch::query()->withTrashed()->find($import->branch_id);
        $editable = in_array($import->status, [InvoiceImportStatus::Review, InvoiceImportStatus::Failed], true);
        $search = is_string($request->query('q')) && trim($request->query('q')) !== '' ? mb_substr(trim($request->query('q')), 0, 60) : null;
        $supplierId = $draft['supplierId'] ?? null;
        $user = $request->user();

        return [
            'import' => [
                'id' => $import->id, 'status' => $import->status->value, 'statusLabel' => $import->status->label(), 'method' => $import->method,
                'error' => $import->error, 'shop' => ['id' => $shop?->id, 'name' => $shop->name ?? 'Unknown shop'],
                'file' => $import->hasFile() ? ['name' => $import->file_name, 'mime' => $import->file_mime, 'size' => $import->file_size] : null,
                'filePurged' => $import->file_purged_at !== null,
                'uploadedBy' => User::query()->find($import->user_id)?->name, 'createdAt' => $import->created_at?->toIso8601ZuluString(),
                'readAt' => $import->extracted_at?->toIso8601ZuluString(), 'tokens' => $import->input_tokens + $import->output_tokens,
                'confirmedAt' => $import->confirmed_at?->toIso8601ZuluString(), 'result' => $import->result,
            ],
            'draft' => $draft,
            'analysis' => $draft === null ? null : InvoiceAnalysis::of($draft, $import->id),
            'suppliers' => Supplier::query()->where('is_active', true)->orWhere('id', $supplierId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => (string) $s->name])->values()->all(),
            'orders' => $editable ? self::orders($import->branch_id, $supplierId, $draft['purchaseOrderId'] ?? null) : [],
            'deliveries' => $editable ? self::deliveries($import->branch_id, $supplierId, $draft['goodsReceiptId'] ?? null) : [],
            'linked' => $draft === null ? null : self::linked($draft),
            'vatRates' => VatRate::query()->orderBy('percentage')->get(['id', 'name', 'percentage'])
                ->map(fn (VatRate $v) => ['id' => $v->id, 'name' => (string) $v->name, 'percentage' => Money::normalise($v->percentage ?? 0, 2)])->values()->all(),
            'search' => $search,
            'results' => $search === null ? [] : self::products($search),
            'can' => [
                'edit' => $editable,
                'confirm' => $import->status === InvoiceImportStatus::Review,
                'discard' => $import->status->isOpen(),
                'retry' => $import->status === InvoiceImportStatus::Failed && $import->hasFile() && $user !== null
                    && InvoiceImportAccess::reader($user, app(CurrentCompany::class)->require())['available'],
                'order' => InvoiceImportAccess::canOrder(),
                'costs' => InvoiceImportAccess::canUpdateCosts(),
            ],
        ];
    }

    /** @return list<array{id: string, label: string}> */
    private static function orders(string $shopId, ?string $supplierId, ?string $current): array
    {
        $orders = InvoiceDocuments::openOrders($shopId, $supplierId);

        if ($current !== null && ! $orders->contains('id', $current)) {
            $orders->push(...PurchaseOrder::query()->whereKey($current)->get());
        }

        return $orders->map(fn (PurchaseOrder $o) => [
            'id' => $o->id, 'label' => HeadOfficeOrders::reference($o).' · '.($o->status->value ?? '').' · '.MoneyFormat::format($o->gross_total, ukStyle: MoneyFormat::AS_GIVEN),
        ])->values()->all();
    }

    /** @return list<array{id: string, label: string}> */
    private static function deliveries(string $shopId, ?string $supplierId, ?string $current): array
    {
        return GoodsReceipt::query()->where('branch_id', $shopId)->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->where(fn ($q) => $q->where('status', '!=', GoodsReceiptStatus::Cancelled->value)->orWhere('id', $current))
            ->orderByDesc('received_date')->limit(30)->get()
            ->map(fn (GoodsReceipt $g) => ['id' => $g->id, 'label' => $g->delivery_note_number.' · '.$g->received_date->format('j M Y').' · '.MoneyFormat::format($g->gross_amount, ukStyle: MoneyFormat::AS_GIVEN)])
            ->values()->all();
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{order: array{id: string, reference: string, status: string|null}|null, delivery: array{id: string, reference: string, date: string}|null}
     */
    private static function linked(array $draft): array
    {
        $order = ($draft['purchaseOrderId'] ?? null) !== null ? PurchaseOrder::query()->find($draft['purchaseOrderId']) : null;
        $delivery = ($draft['goodsReceiptId'] ?? null) !== null ? GoodsReceipt::query()->find($draft['goodsReceiptId']) : null;

        return [
            'order' => $order === null ? null : ['id' => $order->id, 'reference' => HeadOfficeOrders::reference($order), 'status' => $order->status?->value],
            'delivery' => $delivery === null ? null : ['id' => $delivery->id, 'reference' => (string) $delivery->delivery_note_number, 'date' => $delivery->received_date->format('Y-m-d')],
        ];
    }

    /** @return list<array{id: string, name: string, sku: string|null, costPrice: string}> */
    private static function products(string $search): array
    {
        $like = '%'.addcslashes($search, '%_\\').'%';

        return Product::query()->whereNull('archived_at')
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)
                ->orWhereIn('id', DB::table('product_barcodes')->where('company_id', app(CurrentCompany::class)->id())->where('barcode', $search)->select('product_id')))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'sku', 'cost_price'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => (string) $p->name, 'sku' => $p->sku ?: null, 'costPrice' => Money::normalise($p->cost_price ?? 0, 4)])
            ->values()->all();
    }
}
