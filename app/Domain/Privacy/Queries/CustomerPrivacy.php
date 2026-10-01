<?php

namespace App\Domain\Privacy\Queries;

use App\Domain\Customers\Queries\CustomerLedger;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Customer;

/**
 * The customer page's Privacy tab (module 7.7, owners only): this customer's data requests, and whether they can be
 * anonymised now (not already anonymised, account settled). Runs in the company scope.
 */
final class CustomerPrivacy
{
    /**
     * @return array{requests: list<array<string, mixed>>, balance: string, settled: bool, anonymised: bool}
     */
    public static function for(Customer $customer): array
    {
        $balance = CustomerLedger::totals($customer->id)['balance'];

        return [
            'requests' => PrivacyPage::forCustomer($customer->id),
            'balance' => $balance,
            'settled' => Money::isZero($balance),
            'anonymised' => $customer->anonymised_at !== null,
        ];
    }
}
