<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldStockGuard;
use App\Domain\Handheld\Support\PdaErrors;
use App\Domain\Handheld\Support\PdaStockErrors;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Actions\SaveStockDraft;
use App\Domain\StockDrafts\Data\StockDraftData;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\StockDrafts\Queries\PurchaseOrderStock;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/pda/purchase-orders/{id}/receive` (PDA_PHASE2_REQUEST §3): one delivery against an order of the user's
 * shop (a head-office order or the till's own), drafted as the till's own `GoodsReceipt` with `origin: headOffice`
 * (contract proposal pda-stock-drafts §5.3). The till posts it at its next sync.
 *
 * - `quantity_received` is what arrived in THIS delivery (a delta, base units). A line is named by
 *   `purchase_order_item_id` (or `product_id`). No `items`: everything still outstanding.
 * - Outstanding = ordered − booked by the till − handheld deliveries it has not posted yet; more → 422 OVER_RECEIPT
 *   naming the line. A cancelled order → 409 PO_CANCELLED, a draft (not placed) → 409 PO_NOT_RECEIVABLE.
 * - "can adjust stock" only; products not archived, tracking stock. A repeated `client_reference` makes nothing
 *   and answers the first delivery (200), whatever the order looks like now.
 */
final class ReceiveHandheldDelivery
{
    public function __construct(private readonly SaveStockDraft $save) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: StockDraft, 1: bool} the delivery and whether this call made it
     *
     * @throws ApiException|ValidationException
     */
    public function handle(HandheldUser $user, Branch $shop, PurchaseOrder $order, array $input, ?string $clientReference): array
    {
        HandheldStockGuard::assertMayAdjust($user);
        $existing = SaveStockDraft::existing($shop, StockDraftKind::GoodsReceipt, $clientReference);

        if ($existing !== null) {
            return [$existing, false];
        }

        if ($order->status === PurchaseOrderStatus::Cancelled) {
            throw PdaErrors::orderCancelled();
        }

        if ($order->status === PurchaseOrderStatus::Draft) {
            throw PdaStockErrors::notReceivable();
        }

        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->orderBy('position')->get()->keyBy('id');
        $pending = PurchaseOrderStock::pendingReceived($lines->keys()->map(fn ($id) => (string) $id)->all());
        $outstanding = $lines->map(fn (PurchaseOrderLine $l) => self::atLeastZero(Money::sub(Money::sub($l->ordered_units, $l->received_qty, 4), $pending[$l->id] ?? 0, 4)));

        return $this->save->create($shop, new StockDraftData(StockDraftKind::GoodsReceipt, [
            'supplier_id' => $order->supplier_id,
            'supplier_name' => mb_substr((string) Supplier::query()->withTrashed()->whereKey($order->supplier_id)->value('name'), 0, 255),
            'purchase_order_id' => $order->id,
            'reference_no' => self::text($input['reference_no'] ?? null, 60),
            'note' => self::text($input['note'] ?? null, 500),
            'happened_at' => ApiDate::parse($input['received_at'] ?? null) ?? now('UTC'),
        ], $this->lines($lines, $outstanding, $input['items'] ?? null), $clientReference, $user->id, HandheldStockGuard::enteredBy($user)));
    }

    /**
     * @param  Collection<string, PurchaseOrderLine>  $lines
     * @param  Collection<string, string>  $outstanding
     * @return list<array<string, mixed>>
     *
     * @throws ApiException|ValidationException
     */
    private function lines(Collection $lines, Collection $outstanding, mixed $items): array
    {
        $wanted = [];

        if (! is_array($items)) {
            foreach ($outstanding as $id => $left) {
                if (Money::compare($left, '0') > 0) {
                    $wanted[] = ['line' => $lines[$id], 'qty' => $left, 'field' => 'items'];
                }
            }

            if ($wanted === []) {
                throw ValidationException::withMessages(['items' => 'Nothing is left to receive on this order.']);
            }
        }

        foreach (is_array($items) ? array_values($items) : [] as $i => $item) {
            $line = $this->line($lines, $item, $i);

            if (in_array($line->id, array_map(fn (array $w) => $w['line']->id, $wanted), true)) {
                throw ValidationException::withMessages(["items.{$i}.purchase_order_item_id" => 'This order line is in the delivery twice.']);
            }

            $wanted[] = ['line' => $line, 'qty' => Money::normalise($item['quantity_received'], 4), 'field' => "items.{$i}"];
        }

        $over = [];

        foreach ($wanted as $w) {
            if (Money::compare($w['qty'], $outstanding[$w['line']->id]) > 0) {
                $over[$w['field'].'.quantity_received'] = ['Only '.$outstanding[$w['line']->id].' left to receive on this line.'];
            }
        }

        if ($over !== []) {
            throw PdaStockErrors::overReceipt($over);
        }

        return array_map(function (array $w) use ($outstanding): array {
            $line = $w['line'];
            $product = HandheldStockGuard::product((string) $line->product_id, $w['field'] === 'items' ? 'items' : $w['field'].'.purchase_order_item_id');

            return [
                'purchase_order_line_id' => $line->id, 'product_id' => $product->id, 'product_name' => mb_substr($product->name, 0, 255),
                'quantity' => $w['qty'], 'expected_quantity' => $outstanding[$line->id], 'unit_cost' => Money::normalise($line->unit_cost_snapshot, 4),
                'vat_rate_id' => $line->vat_rate_id, 'vat_percentage' => Money::normalise($line->vat_percentage, 4), 'case_qty' => max(1, (int) $line->case_qty_snapshot),
            ];
        }, $wanted);
    }

    /**
     * @param  Collection<string, PurchaseOrderLine>  $lines
     *
     * @throws ValidationException
     */
    private function line(Collection $lines, mixed $item, int $i): PurchaseOrderLine
    {
        $id = is_array($item) && is_string($item['purchase_order_item_id'] ?? null) ? $item['purchase_order_item_id'] : null;
        $product = is_array($item) && is_string($item['product_id'] ?? null) ? $item['product_id'] : null;
        $line = $id !== null ? $lines->get($id) : $lines->first(fn (PurchaseOrderLine $l) => $product !== null && $l->product_id === $product);

        return $line instanceof PurchaseOrderLine ? $line
            : throw ValidationException::withMessages(["items.{$i}.purchase_order_item_id" => 'This is not a line of this order.']);
    }

    private static function atLeastZero(string $value): string
    {
        return Money::isNegative($value) ? '0.0000' : $value;
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
