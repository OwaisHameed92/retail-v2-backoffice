<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Invoices\InvoiceDraft;
use App\Domain\Purchasing\Invoices\InvoiceMatcher;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\TillData\Models\GoodsReceipt;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Validation\ValidationException;

/**
 * Saves the user's corrections to an invoice under review (module 6.5) and matches again: lines the user pointed at
 * a product, the supplier they picked and the order or delivery they linked are kept (pinned); everything else is
 * matched afresh. Ids are checked against this business (and the import's shop for the order and delivery). An
 * import that failed to read becomes a manual entry.
 */
final class SaveInvoiceReview
{
    public function __construct(private readonly InvoiceMatcher $matcher) {}

    /**
     * @param  array<string, mixed>  $input  the review form (InvoiceReviewRequest)
     *
     * @throws ValidationException
     */
    public function handle(InvoiceImport $import, array $input): InvoiceImport
    {
        if (! in_array($import->status, [InvoiceImportStatus::Review, InvoiceImportStatus::Failed], true)) {
            throw ValidationException::withMessages(['import' => 'This import is '.mb_strtolower($import->status->label()).' and can no longer be changed.']);
        }

        $draft = InvoiceDraft::fromForm($input, $import->draft ?? []);

        if ($draft['supplierPinned'] && $draft['supplierId'] !== null && ! Supplier::query()->whereKey($draft['supplierId'])->exists()) {
            throw ValidationException::withMessages(['supplierId' => 'Choose a supplier from the list.']);
        }

        if ($draft['documentPinned']) {
            $this->checkDocuments($import, $draft);
        }

        $import->fill([
            'status' => InvoiceImportStatus::Review,
            'method' => $import->status === InvoiceImportStatus::Failed ? 'manual' : $import->method,
            'error' => null,
            'draft' => $this->matcher->apply($draft, $import->branch_id),
        ])->syncHeader()->save();

        return $import;
    }

    /**
     * @param  array<string, mixed>  $draft
     *
     * @throws ValidationException
     */
    private function checkDocuments(InvoiceImport $import, array $draft): void
    {
        if ($draft['purchaseOrderId'] !== null && ! PurchaseOrder::query()->whereKey($draft['purchaseOrderId'])->where('branch_id', $import->branch_id)->exists()) {
            throw ValidationException::withMessages(['purchaseOrderId' => 'Choose one of this shop\'s orders.']);
        }

        if ($draft['goodsReceiptId'] !== null && ! GoodsReceipt::query()->whereKey($draft['goodsReceiptId'])->where('branch_id', $import->branch_id)->exists()) {
            throw ValidationException::withMessages(['goodsReceiptId' => 'Choose one of this shop\'s deliveries.']);
        }
    }
}
