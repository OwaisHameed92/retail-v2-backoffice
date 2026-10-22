<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldStockGuard;
use App\Domain\Handheld\Support\PdaStockErrors;
use App\Domain\Handheld\Support\StockReasons;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Actions\SaveStockDraft;
use App\Domain\StockDrafts\Data\StockDraftData;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Exceptions\StockDraftLocked;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\StockDrafts\Queries\PurchaseOrderStock;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use App\Domain\TillData\Models\Supplier;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/pda/purchase-returns` and `PUT …/{id}` (PDA_PHASE2_REQUEST §4): goods going back to a supplier from the
 * user's shop, drafted as the till's own `PurchaseReturn` with `origin: headOffice` (contract proposal
 * pda-stock-drafts §5.4, number HR-{shop}-000001). The till books it at its next sync.
 *
 * - A supplier of the business; optionally an order of this shop from that supplier, and per item an order line of
 *   it (`purchase_order_item_id`, the line's product). Against a line: at most received − already returned (the
 *   till's returns and other handheld returns; handheld deliveries not posted yet count as received) → else 422
 *   OVER_RETURN naming the item.
 * - Cost per base unit ex VAT: `cost_price`, else the order line's, else the product's. VAT from the product.
 * - Line reason "Damaged" / "Wrong item" / "Expired" / "Recalled" / "Other" or any text (code `other`, the text kept).
 * - "can adjust stock" only; products not archived, tracking stock. `client_reference` as the other stock calls;
 *   edits while the portal owns it (409 RETURN_OWNED_BY_TILL / RETURN_CANCELLED after).
 */
final class SaveHandheldPurchaseReturn
{
    public function __construct(private readonly SaveStockDraft $save) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: StockDraft, 1: bool}
     *
     * @throws ApiException|ValidationException
     */
    public function create(HandheldUser $user, Branch $shop, array $input, ?string $clientReference): array
    {
        HandheldStockGuard::assertMayAdjust($user);
        $existing = SaveStockDraft::existing($shop, StockDraftKind::PurchaseReturn, $clientReference);

        return $existing !== null ? [$existing, false] : $this->save->create($shop, $this->data($user, $shop, $input, $clientReference, null));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApiException|ValidationException
     */
    public function update(HandheldUser $user, Branch $shop, StockDraft $draft, array $input): StockDraft
    {
        HandheldStockGuard::assertMayAdjust($user);
        HandheldStockGuard::unlocked(fn () => StockDraftLocked::unlessPending($draft));
        $data = $this->data($user, $shop, $input, null, $draft->id);

        return HandheldStockGuard::unlocked(fn () => $this->save->update($draft, $data));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApiException|ValidationException
     */
    private function data(HandheldUser $user, Branch $shop, array $input, ?string $clientReference, ?string $draftId): StockDraftData
    {
        $supplier = Supplier::query()->find((string) $input['supplier_id']);

        if (! $supplier instanceof Supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose one of this business\'s suppliers.']);
        }

        $order = $this->order($shop, $supplier, $input['purchase_order_id'] ?? null);
        $orderLines = $order === null ? collect() : PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->get()->keyBy('id');
        $lines = [];

        foreach (array_values((array) $input['items']) as $i => $item) {
            $product = HandheldStockGuard::product((string) $item['product_id'], "items.{$i}.product_id");
            $lineId = is_string($item['purchase_order_item_id'] ?? null) && $item['purchase_order_item_id'] !== '' ? $item['purchase_order_item_id'] : null;
            $orderLine = $lineId === null ? null : $this->orderLine($orderLines->all(), $lineId, $order !== null, $i);

            if ($orderLine !== null && $orderLine->product_id !== $product->id) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => 'This is not the product of that order line.']);
            }

            [$reason, $note] = StockReasons::parse(is_string($item['reason'] ?? null) ? $item['reason'] : null, StockReasons::RETURN);
            $cost = $item['cost_price'] ?? ($orderLine === null ? $product->cost_price : $orderLine->unit_cost_snapshot);
            $lines[] = [
                'product_id' => $product->id, 'product_name' => mb_substr($product->name, 0, 255), 'purchase_order_line_id' => $orderLine === null ? null : $orderLine->id,
                'quantity' => Money::normalise($item['quantity'], 4),
                'unit_cost' => Money::normalise($cost, 4),
                'vat_rate_id' => $product->vat_rate_id,
                'vat_percentage' => Money::normalise(VatRate::query()->whereKey($product->vat_rate_id)->value('percentage') ?? 0, 4),
                'reason' => $reason ?? 'other', 'note' => $note === '' ? null : $note,
            ];
        }

        $this->assertReturnable($lines, $orderLines->all(), $draftId);

        return new StockDraftData(StockDraftKind::PurchaseReturn, [
            'supplier_id' => $supplier->id,
            'supplier_name' => mb_substr($supplier->name, 0, 255),
            'purchase_order_id' => $order?->id,
            'reference_no' => is_string($input['reference_no'] ?? null) && trim($input['reference_no']) !== '' ? mb_substr(trim($input['reference_no']), 0, 60) : null,
            'note' => is_string($input['return_reason'] ?? null) && trim($input['return_reason']) !== '' ? mb_substr(trim($input['return_reason']), 0, 500) : null,
            'happened_at' => now('UTC'),
        ], $lines, $clientReference, $user->id, HandheldStockGuard::enteredBy($user));
    }

    /**
     * @param  array<string, PurchaseOrderLine>  $orderLines
     *
     * @throws ValidationException
     */
    private function orderLine(array $orderLines, string $lineId, bool $hasOrder, int $i): PurchaseOrderLine
    {
        return $orderLines[$lineId] ?? throw ValidationException::withMessages(["items.{$i}.purchase_order_item_id" => $hasOrder
            ? 'This is not a line of this order.' : 'Give purchase_order_id with an order line.']);
    }

    /**
     * @throws ValidationException
     */
    private function order(Branch $shop, Supplier $supplier, mixed $id): ?PurchaseOrder
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        $order = PurchaseOrder::query()->where('branch_id', $shop->id)->find($id);

        if (! $order instanceof PurchaseOrder) {
            throw ValidationException::withMessages(['purchase_order_id' => 'This is not an order of your shop.']);
        }

        if ($order->supplier_id !== $supplier->id) {
            throw ValidationException::withMessages(['purchase_order_id' => 'This order is from another supplier.']);
        }

        return $order;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, PurchaseOrderLine>  $orderLines
     *
     * @throws ApiException
     */
    private function assertReturnable(array $lines, array $orderLines, ?string $draftId): void
    {
        $ids = array_keys($orderLines);
        $pending = PurchaseOrderStock::pendingReceived($ids);
        $returned = PurchaseOrderStock::returned($ids, $draftId);
        $asked = [];
        $over = [];

        foreach ($lines as $i => $line) {
            $id = $line['purchase_order_line_id'];

            if ($id === null) {
                continue;
            }

            $asked[$id] = Money::add($asked[$id] ?? 0, $line['quantity'], 4);
            $left = Money::sub(Money::add($orderLines[$id]->received_qty, $pending[$id] ?? 0, 4), $returned[$id] ?? 0, 4);
            $left = Money::isNegative($left) ? '0.0000' : $left;

            if (Money::compare($asked[$id], $left) > 0) {
                $over["items.{$i}.quantity"] = ["Only {$left} can be returned on this order line."];
            }
        }

        if ($over !== []) {
            throw PdaStockErrors::overReturn($over);
        }
    }
}
