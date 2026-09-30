<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Actions\DraftHeadOfficeOrder;
use App\Domain\TillData\Data\HeadOfficeOrderData;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Validation\ValidationException;

/**
 * Saves a head-office order from the portal form (module 5.2) for one open shop, as a draft (a heads-up to the shop)
 * or sent (placed with the supplier; the shop can receive at once). Each line's VAT percentage is taken from its VAT
 * rate here, never from the browser. DraftHeadOfficeOrder does the rest: numbering (HO-LDS-000001), the totals, the
 * "shop owns it now" and "never re-open a cancelled order" rules, the audit and the pull to that shop only.
 *
 *     $order = app(SaveHeadOfficeOrder::class)->handle($leeds, $request->validated());
 */
final class SaveHeadOfficeOrder
{
    public function __construct(private readonly DraftHeadOfficeOrder $draft) {}

    /**
     * @param  array<string, mixed>  $input  supplierId, status (draft|sent), expectedDate, notes, lines[] {productId, orderedCases, caseQty, looseUnits, unitCost, vatRateId}
     *
     * @throws ValidationException
     */
    public function handle(Branch $shop, array $input, ?PurchaseOrder $order = null): PurchaseOrder
    {
        if (! $shop->is_active) {
            throw ValidationException::withMessages(['shopId' => 'This shop is closed. Choose an open shop.']);
        }

        if ($order !== null && $order->branch_id !== $shop->id) {
            throw ValidationException::withMessages(['shopId' => 'An order stays with the shop it was drafted for. Draft a new order for another shop.']);
        }

        $lines = array_values((array) ($input['lines'] ?? []));
        $rates = VatRate::query()->whereKey(array_column($lines, 'vatRateId'))->pluck('percentage', 'id');

        return $this->draft->handle($shop, HeadOfficeOrderData::fromArray([
            'supplierId' => $input['supplierId'] ?? '',
            'status' => in_array($input['status'] ?? null, ['draft', 'sent'], true) ? $input['status'] : 'draft',
            'expectedDate' => ($input['expectedDate'] ?? null) ?: null,
            'notes' => ($input['notes'] ?? null) ?: null,
            'lines' => array_map(fn (array $line) => [...$line, 'vatPercentage' => $rates[$line['vatRateId'] ?? ''] ?? 0], $lines),
        ]), $order?->id);
    }
}
