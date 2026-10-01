<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Queries\PurchasingPage;
use App\Domain\Purchasing\Reorder\ReorderOrderPlan;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates draft head-office orders from reorder suggestions (module 6.4): one order per shop and supplier, through
 * SaveHeadOfficeOrder → DraftHeadOfficeOrder (numbering, totals, audit, the pull to that shop only). Drafts only:
 * nothing goes to a supplier until someone sends the order. All or nothing. Same rule as the order form:
 * `purchasing.manage` and every shop.
 *
 *     $orders = app(CreateReorderOrders::class)->handle([['shopId' => …, 'supplierId' => …, 'productId' => …, 'cases' => 2]]);
 */
final class CreateReorderOrders
{
    public function __construct(private readonly SaveHeadOfficeOrder $save) {}

    /**
     * @param  list<array{shopId: string, supplierId: string, productId: string, cases: int}>  $lines
     * @return list<PurchaseOrder>
     *
     * @throws ValidationException
     */
    public function handle(array $lines, ?string $notes = null): array
    {
        if (! PurchasingPage::canManage()) {
            throw ValidationException::withMessages(['lines' => 'Only a user who can see every shop can draft head-office orders.']);
        }

        $plan = ReorderOrderPlan::build($lines);

        return DB::transaction(fn () => array_map(fn (array $order) => $this->save->handle($order['shop'], [
            'supplierId' => $order['supplier']->id,
            'status' => 'draft',
            'notes' => $notes ?: 'From reorder suggestions.',
            'lines' => array_map(fn (array $l) => [
                'productId' => $l['productId'], 'orderedCases' => $l['cases'], 'caseQty' => $l['caseQty'], 'looseUnits' => 0,
                'unitCost' => $l['unitCost'], 'vatRateId' => $l['vatRateId'],
            ], $order['lines']),
        ]), $plan));
    }
}
