<?php

namespace App\Domain\Privacy\Support;

use App\Domain\TillData\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Erasure (module 7.7, ANSWERS-2026-10-06 "Purane khule sawal" 3): the portal's own copies of till-owned rows that
 * carry an anonymised customer's details are scrubbed here. The tills do not clear them when the anonymised
 * `Customer` arrives, and these tables are branch-owned (ownership.json), so nothing here is ever sent down (pull
 * sends hub-owned rows only); the till steps on the request still ask the shops to clear their own copies.
 *
 * - `customer_orders` of the customer: name, phone and email emptied.
 * - `account_pay_dates` of the customer: the note emptied.
 * - `e_receipt_logs` of the customer's sales (`sale_id` → `sales.customer_id`, a reliable link): the address emptied.
 * - `consents` of the customer hold no contact details (channel, given, dates); they are kept as the record of what
 *   was agreed or withdrawn.
 *
 * On all four, `extra` (members a newer till sent that the contract does not list yet) is cleared too. Query builder
 * on purpose (the models are read-only till rows), always filtered by the company: another business's rows are never
 * touched. A later push of the same row from a till that was not cleared brings its details back; the till step
 * covers that.
 */
final class TillCopyScrub
{
    /**
     * @return array{customerOrders: int, accountPayDates: int, eReceipts: int, consents: int}
     */
    public static function handle(string $companyId, Customer $customer): array
    {
        $ofCustomer = fn (string $table) => DB::table($table)->where('company_id', $companyId)->where('customer_id', $customer->id);
        $sales = DB::table('sales')->where('company_id', $companyId)->where('customer_id', $customer->id)->select('id');

        return [
            'customerOrders' => $ofCustomer('customer_orders')->update(['customer_name' => '', 'customer_phone' => '', 'customer_email' => '', 'extra' => null]),
            'accountPayDates' => $ofCustomer('account_pay_dates')->update(['note' => '', 'extra' => null]),
            'eReceipts' => DB::table('e_receipt_logs')->where('company_id', $companyId)->whereIn('sale_id', $sales)->update(['address' => '', 'extra' => null]),
            // Only rows that carry `extra`: MySQL counts changed rows, SQLite matched ones.
            'consents' => $ofCustomer('consents')->whereNotNull('extra')->update(['extra' => null]),
        ];
    }
}
