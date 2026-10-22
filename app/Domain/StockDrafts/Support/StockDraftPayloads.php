<?php

namespace App\Domain\StockDrafts\Support;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\StockDrafts\Models\StockDraftLine;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Support\PullPayload;
use Illuminate\Support\Collection;

/**
 * A stock draft → the payload the till pulls, exactly as contract proposal pda-stock-drafts §5 (schemas
 * `docs/contracts/proposals/pda-stock-drafts/schemas`): a `StockAdjustmentDraft`, or the till's own `StockTake`,
 * `GoodsReceipt`, `PurchaseReturn` (+ lines) with `origin: headOffice`. Every member is written (nulls too); the
 * till's outcome members are null / 0 (never read from a pull); our users are not till users (`""`); `rowVersion` 0.
 * Company and shop ids are the pulling till's (PullPayload::till).
 */
final class StockDraftPayloads
{
    public function __construct(private readonly PullPayload $ids) {}

    /**
     * @param  Collection<int, StockDraftLine>  $lines  the draft's lines, in order
     * @return array<string, mixed>
     */
    public function header(StockDraft $draft, Collection $lines): array
    {
        $shop = $this->ids->till(IdKind::Branch, $draft->branch_id);
        [$net, $vat] = self::totals($lines);

        $payload = match ($draft->kind) {
            StockDraftKind::Adjustment => [
                'productId' => $draft->product_id, 'type' => $draft->adjustment_type, 'quantity' => self::qty($draft->quantity),
                'unitCost' => in_array($draft->adjustment_type, ['stockIncrease', 'openingStock'], true) ? PullPayload::value('cost', $draft->unit_cost) : null,
                'reason' => $draft->reason, 'note' => (string) $draft->note, 'productUnitId' => $draft->product_unit_id,
                'enteredQuantity' => PullPayload::value('quantity', $draft->entered_quantity), 'conversionToBase' => PullPayload::value('quantity', $draft->conversion_to_base),
                'enteredAt' => PullPayload::dateTime($draft->happened_at), 'enteredBy' => $draft->entered_by, 'status' => 'pending',
                'confirmedAt' => null, 'rejectedAt' => null, 'rejectReason' => null, 'qtyBefore' => null, 'qtyDelta' => null, 'qtyAfter' => null, 'stockMovementId' => null,
            ],
            StockDraftKind::StockTake => [
                'reference' => $draft->reference, 'name' => (string) $draft->name, 'scope' => 'productList', 'scopeId' => '', 'status' => 'review',
                'isBlind' => false, 'countUncountedAsZero' => false, 'startedByUserId' => '', 'startedAt' => PullPayload::dateTime($draft->happened_at),
                'approvedByUserId' => '', 'approvedAt' => null, 'cancelledAt' => null, 'recountThresholdValue' => 0, 'recountThresholdPercent' => 0,
                'approvalOverValue' => 0, 'note' => (string) $draft->note, 'isHighValueCount' => false, 'isClosed' => false, 'origin' => 'headOffice', 'cancelReason' => null,
            ],
            StockDraftKind::GoodsReceipt => [
                'supplierId' => $draft->supplier_id, 'supplierName' => (string) $draft->supplier_name, 'purchaseOrderId' => $draft->purchase_order_id,
                'deliveryNoteNumber' => (string) $draft->reference_no, 'receivedDate' => self::date($draft), 'status' => 'draft', 'receivedByUserId' => '',
                'netAmount' => PullPayload::value('money', $net), 'vatAmount' => PullPayload::value('money', $vat), 'grossAmount' => PullPayload::value('money', Money::add($net, $vat, 2)),
                'postedAt' => null, 'cancelledAt' => null, 'cancelReason' => null, 'note' => $draft->note, 'origin' => 'headOffice',
            ],
            StockDraftKind::PurchaseReturn => [
                'supplierId' => $draft->supplier_id, 'supplierName' => (string) $draft->supplier_name, 'number' => (int) $draft->number, 'reference' => $draft->reference,
                'status' => 'draft', 'returnDate' => self::date($draft), 'goodsReceiptId' => null, 'purchaseOrderId' => $draft->purchase_order_id,
                'netAmount' => PullPayload::value('money', $net), 'vatAmount' => PullPayload::value('money', $vat), 'grossAmount' => PullPayload::value('money', Money::add($net, $vat, 2)),
                'createdByUserId' => '', 'sentAt' => null, 'sentByUserId' => null, 'creditNoteNumber' => '', 'creditDate' => null, 'creditedAt' => null,
                'supplierCreditNoteId' => null, 'cancelledAt' => null, 'note' => self::returnNote($draft), 'origin' => 'headOffice', 'cancelReason' => null,
            ],
        };

        return [...$payload, 'branchId' => $shop, ...$this->row($draft->id, $draft)];
    }

    /**
     * @return array<string, mixed>
     */
    public function line(StockDraftLine $line, StockDraft $draft): array
    {
        $payload = match ($draft->kind) {
            StockDraftKind::StockTake => [
                'stockTakeId' => $draft->id, 'productId' => $line->product_id, 'productName' => $line->product_name, 'sectionId' => '', 'snapshotQty' => 0,
                'countedQty' => self::qty($line->quantity), 'unitCost' => 0, 'countCount' => 1, 'countedByUserId' => '',
                'countedAt' => PullPayload::dateTime($draft->happened_at), 'note' => '', 'recountRequired' => false, 'isCounted' => true,
                'varianceQty' => 0, 'varianceCost' => 0, 'branchId' => $this->ids->till(IdKind::Branch, $draft->branch_id),
            ],
            StockDraftKind::GoodsReceipt => [
                'goodsReceiptId' => $draft->id, 'purchaseOrderLineId' => $line->purchase_order_line_id, 'productId' => $line->product_id,
                'expectedProductId' => $line->product_id, 'expectedQty' => self::qty($line->expected_quantity ?? 0), 'receivedQty' => self::qty($line->quantity),
                'damagedQty' => 0, 'caseQty' => max(1, (int) $line->case_qty), 'unitCost' => PullPayload::value('cost', $line->unit_cost ?? 0),
                'vatRateId' => $line->vat_rate_id, 'vatPercentage' => PullPayload::value('percent', $line->vat_percentage ?? 0), 'isShortOrOver' => false,
                'note' => '', 'expiryDate' => null, 'batchNo' => null,
            ],
            default => [
                'purchaseReturnId' => $draft->id, 'productId' => $line->product_id, 'productName' => $line->product_name, 'qty' => self::qty($line->quantity),
                'unitCost' => PullPayload::value('cost', $line->unit_cost ?? 0), 'vatRateId' => $line->vat_rate_id,
                'vatPercentage' => PullPayload::value('percent', $line->vat_percentage ?? 0), 'reason' => $line->reason ?? 'other', 'fromStock' => true,
                'goodsReceiptLineId' => null, 'lineNet' => PullPayload::value('money', self::net($line)), 'lineVat' => PullPayload::value('money', self::vat($line)),
                'note' => (string) $line->note, 'purchaseOrderLineId' => $line->purchase_order_line_id,
            ],
        };

        return [...$payload, ...$this->row($line->id, $line)];
    }

    /**
     * Net and VAT of a delivery or a return (2 dp, VAT per line).
     *
     * @param  Collection<int, StockDraftLine>  $lines
     * @return array{0: string, 1: string}
     */
    public static function totals(Collection $lines): array
    {
        return [Money::sum($lines->map(fn (StockDraftLine $l) => self::net($l)), 2), Money::sum($lines->map(fn (StockDraftLine $l) => self::vat($l)), 2)];
    }

    public static function net(StockDraftLine $line): string
    {
        return Money::mul($line->quantity, $line->unit_cost ?? 0, 2);
    }

    public static function vat(StockDraftLine $line): string
    {
        return Money::mul(self::net($line), Money::mul($line->vat_percentage ?? 0, '0.01', 6), 2);
    }

    /**
     * The members every row has (§5): ids in the till's terms, our times, `rowVersion` 0 (the shop's first push wins).
     *
     * @return array<string, mixed>
     */
    private function row(string $id, StockDraft|StockDraftLine $model): array
    {
        return [
            'id' => $id, 'companyId' => $this->ids->till(IdKind::Company, $model->company_id),
            'createdAt' => PullPayload::dateTime($model->created_at), 'updatedAt' => PullPayload::dateTime($model->updated_at),
            'rowVersion' => 0, 'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => [],
        ];
    }

    private static function qty(mixed $value): mixed
    {
        return PullPayload::value('quantity', $value ?? 0);
    }

    /** The shop's calendar date of the delivery or return. */
    private static function date(StockDraft $draft): string
    {
        return $draft->happened_at->setTimezone(Country::zone())->format('Y-m-d');
    }

    private static function returnNote(StockDraft $draft): ?string
    {
        $note = trim((string) $draft->note);
        $reference = trim((string) $draft->reference_no);
        $text = trim($note.($reference !== '' ? ($note !== '' ? ' — ' : '').'ref '.$reference : ''));

        return $text === '' ? null : $text;
    }
}
