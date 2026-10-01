<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Invoices\InvoiceAnalysis;
use App\Domain\Purchasing\Invoices\InvoiceImportAccess;
use App\Domain\Purchasing\Invoices\InvoiceOrderLines;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirms a reviewed invoice (module 6.5). `SupplierInvoice`, `GoodsReceipt` and the shop's orders are till-owned
 * (ownership.json), so the portal never writes them; confirming records the invoice on the portal and, when asked:
 *
 * - `order` draft|sent: a head-office order for the import's shop from its matched lines (SaveHeadOfficeOrder, the
 *   `hubDrafted` PurchaseOrder the contract allows), so the shop can book the delivery in against it. Not when the
 *   invoice is already linked to one of the shop's orders. Needs a user of every shop.
 * - `costUpdates`: product ids whose cost price becomes the invoice's cost per item, only from the preview
 *   (InvoiceAnalysis::costChanges) and through SaveProduct (hub-owned, audited, pulled by every till). Needs
 *   catalogue.manage and every shop.
 *
 * Warnings (sums that do not add up, duplicates, unmatched lines) must be acknowledged; errors block.
 */
final class ConfirmInvoiceImport
{
    public function __construct(
        private readonly SaveHeadOfficeOrder $orders,
        private readonly SaveProduct $products,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  array{order?: string|null, costUpdates?: list<string>|null, acknowledged?: bool|null}  $options
     *
     * @throws ValidationException|AuthorizationException
     */
    public function handle(InvoiceImport $import, User $user, array $options): InvoiceImport
    {
        if ($import->status !== InvoiceImportStatus::Review || $import->draft === null) {
            throw ValidationException::withMessages(['import' => 'Only an invoice waiting for review can be confirmed.']);
        }

        $draft = $import->draft;
        $analysis = InvoiceAnalysis::of($draft, $import->id);
        $levels = array_column($analysis['issues'], 'level');
        $order = in_array($options['order'] ?? null, ['draft', 'sent'], true) ? $options['order'] : null;
        $costIds = array_values(array_unique(array_map('strval', $options['costUpdates'] ?? [])));

        if (in_array('error', $levels, true)) {
            throw ValidationException::withMessages(['import' => collect($analysis['issues'])->firstWhere('level', 'error')['message'] ?? 'Fix the invoice first.']);
        }

        if (in_array('warning', $levels, true) && ! ($options['acknowledged'] ?? false)) {
            throw ValidationException::withMessages(['acknowledged' => 'Some figures need checking. Tick that you have checked them to confirm anyway.']);
        }

        if (($order !== null && ! InvoiceImportAccess::canOrder()) || ($costIds !== [] && ! InvoiceImportAccess::canUpdateCosts())) {
            throw new AuthorizationException('Only someone who manages every shop can do that.');
        }

        $changes = collect($analysis['costChanges'])->keyBy('productId');

        if (array_diff($costIds, $changes->keys()->all()) !== []) {
            throw ValidationException::withMessages(['costUpdates' => 'The cost changes have changed. Check the preview again.']);
        }

        $orderLines = $order === null ? [] : InvoiceOrderLines::from($draft, $analysis);

        if ($order !== null) {
            match (true) {
                $draft['purchaseOrderId'] !== null => throw ValidationException::withMessages(['order' => 'This invoice is for one of the shop\'s orders already. The shop books it in against that order.']),
                $draft['supplierId'] === null => throw ValidationException::withMessages(['order' => 'Pick the supplier to place an order.']),
                $orderLines === [] => throw ValidationException::withMessages(['order' => 'Match at least one line to a product to place an order.']),
                default => null,
            };
        }

        return DB::transaction(function () use ($import, $user, $draft, $order, $orderLines, $costIds, $changes, $analysis) {
            $result = ['order' => null, 'costUpdates' => [], 'lines' => count($draft['lines']), 'net' => $analysis['totals']['net'], 'gross' => $analysis['totals']['gross']];

            if ($order !== null) {
                $shop = Branch::query()->findOrFail($import->branch_id);
                $saved = $this->orders->handle($shop, [
                    'supplierId' => $draft['supplierId'], 'status' => $order, 'expectedDate' => null,
                    'notes' => mb_substr('From invoice '.$draft['invoiceNumber'].' (invoice import)', 0, 1000), 'lines' => $orderLines,
                ]);
                $result['order'] = ['id' => $saved->id, 'reference' => $saved->reference, 'status' => $saved->status?->value, 'lines' => count($orderLines)];
                $import->purchase_order_id = $saved->id;
            }

            foreach ($costIds as $id) {
                $change = $changes[$id];
                $this->products->handle(Product::query()->findOrFail($id), ['cost_price' => $change['to']]);
                $result['costUpdates'][] = ['productId' => $id, 'name' => $change['name'], 'from' => $change['from'], 'to' => $change['to']];
            }

            $import->fill([
                'status' => InvoiceImportStatus::Confirmed, 'result' => $result,
                'confirmed_at' => CarbonImmutable::now('UTC'), 'confirmed_by_user_id' => $user->getKey(),
            ])->syncHeader()->save();

            $this->audit->handle('invoice_import.confirmed', $import, null, [
                'invoice_number' => $draft['invoiceNumber'], 'supplier_id' => $draft['supplierId'], 'gross' => $analysis['totals']['gross'],
                'order' => $result['order']['reference'] ?? null, 'cost_updates' => count($result['costUpdates']),
            ], ['warnings' => count(array_filter($analysis['issues'], fn ($i) => $i['level'] === 'warning'))]);

            return $import;
        });
    }
}
