<?php

namespace App\Domain\Privacy\Support;

use App\Domain\Customers\Queries\CustomerDetail;
use App\Domain\Privacy\Queries\CustomerDataExport;
use App\Domain\TillData\Models\Customer;
use App\Domain\TillData\Models\EReceiptLog;
use App\Domain\TillData\Models\Sale;

/**
 * What an erasure still needs on the tills (module 7.7, a note for EPOS). These rows are branch-owned in the
 * contract (ownership.json: CustomerOrder, EReceiptLog, Sale), so the portal never writes them: the shop must clear
 * the customer's details there, and the till pushes the change back. Worked out before the customer is anonymised
 * (the matching uses their email, phone and name). Runs in the company scope.
 */
final class TillSteps
{
    /**
     * @return list<array{key: string, text: string, count: int, shops: list<string>}>
     */
    public static function forErasure(Customer $customer): array
    {
        $branches = CustomerDetail::branches();
        $shops = fn (iterable $ids) => collect($ids)->filter()->unique()->map(fn ($id) => $branches[(string) $id] ?? 'Another shop')->sort()->values()->all();
        $steps = [];

        $orders = CustomerDataExport::ordersQuery($customer)->get(['id', 'branch_id']);

        if ($orders->isNotEmpty()) {
            $steps[] = [
                'key' => 'customerOrders',
                'text' => 'Clear the name, phone and email on '.self::plural($orders->count(), 'customer order').' (Customer orders on the till).',
                'count' => $orders->count(),
                'shops' => $shops($orders->pluck('branch_id')),
            ];
        }

        $sales = Sale::query()->where('customer_id', $customer->id)->select('id');
        $receipts = EReceiptLog::query()->whereIn('sale_id', $sales)->where('address', '!=', '')->get(['id', 'branch_id']);

        if ($receipts->isNotEmpty()) {
            $steps[] = [
                'key' => 'eReceipts',
                'text' => 'Clear the email or phone number on '.self::plural($receipts->count(), 'e-receipt record').'.',
                'count' => $receipts->count(),
                'shops' => $shops($receipts->pluck('branch_id')),
            ];
        }

        $name = trim((string) $customer->name);

        if (mb_strlen($name) >= 3) {
            $named = Sale::query()->where('customer_id', $customer->id)->where('receipt_json', 'like', '%'.addcslashes($name, '%_\\').'%')->get(['id', 'branch_id']);

            if ($named->isNotEmpty()) {
                $steps[] = [
                    'key' => 'receipts',
                    'text' => self::plural($named->count(), 'stored receipt').' on the till still print the customer\'s name on a reprint.',
                    'count' => $named->count(),
                    'shops' => $shops($named->pluck('branch_id')),
                ];
            }
        }

        return $steps;
    }

    private static function plural(int $n, string $word): string
    {
        return $n.' '.$word.($n === 1 ? '' : 's');
    }
}
