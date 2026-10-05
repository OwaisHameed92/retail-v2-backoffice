<?php

namespace App\Domain\Customers\Queries;

use App\Domain\Customers\Support\CustomerFormat;
use App\Domain\Customers\Support\MarketingConsent;
use App\Domain\Privacy\Queries\CustomerPrivacy;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Customer;
use Illuminate\Http\Request;

/**
 * Props for the customer page (module 4.4): details, the account worked out from the ledger (never the till's cached
 * figures), the ledger across every shop, per-shop totals and marketing consent with its history.
 * Runs in the company scope.
 */
final class CustomerDetail
{
    /** @return array<string, mixed> */
    public static function for(Request $request, Customer $customer): array
    {
        $tenancy = app(CurrentCompany::class);
        $branches = self::branches();
        $shop = $request->query('shop');
        $shop = is_string($shop) && isset($branches[$shop]) ? $shop : null;
        $type = in_array($request->query('type'), CustomerFormat::TYPE_GROUPS, true) ? (string) $request->query('type') : null;
        $totals = CustomerLedger::totals($customer->id);
        $limit = Money::normalise($customer->credit_limit ?? '0');

        return [
            'customer' => self::form($customer),
            'account' => [
                'balance' => $totals['balance'],
                'points' => $totals['points'],
                'creditLimit' => $limit,
                'available' => Money::isZero($limit) ? null : Money::sub($limit, $totals['balance']),
                'overLimit' => ! Money::isZero($limit) && Money::compare($totals['balance'], $limit) > 0,
                'byShop' => CustomerLedger::byShop($customer->id, $branches),
            ],
            'payDates' => CustomerPayDates::current($customer->id, $branches),
            'ledger' => CustomerLedger::page($request, $customer->id, $branches, $shop, $type),
            'ledgerFilters' => ['shop' => $shop, 'type' => $type],
            'shops' => self::options($branches),
            'consent' => [
                'current' => array_values(MarketingConsent::current($customer->id, $branches)),
                'history' => MarketingConsent::history($customer->id, $branches),
            ],
            'canEdit' => self::canEdit() && $customer->anonymised_at === null,
            'canEmail' => self::canEmail($customer),
            'privacy' => $tenancy->can(Ability::PrivacyManage) ? CustomerPrivacy::for($customer) : null,
            'readOnlyReason' => $tenancy->can(Ability::CustomersManage) && $tenancy->restrictedBranchId() !== null ? 'oneShop' : null,
        ];
    }

    /**
     * The details form's values ("" for empty text, as the form edits them).
     *
     * @return array<string, mixed>
     */
    public static function form(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => (string) $c->name,
            'phone' => (string) $c->phone,
            'email' => (string) $c->email,
            'address' => (string) $c->address,
            'dob' => $c->dob?->format('Y-m-d') ?? '',
            'card_no' => (string) $c->card_no,
            'credit_limit' => Money::normalise($c->credit_limit ?? '0'),
            'tier' => (string) $c->tier,
            'notes' => (string) $c->notes,
            'is_active' => $c->is_active !== false,
            'anonymisedAt' => $c->anonymised_at?->toIso8601ZuluString(),
            'createdAt' => $c->created_at?->toIso8601ZuluString(),
            'updatedAt' => $c->updated_at?->toIso8601ZuluString(),
        ];
    }

    public static function canEdit(): bool
    {
        $tenancy = app(CurrentCompany::class);

        return $tenancy->can(Ability::CustomersManage) && $tenancy->restrictedBranchId() === null;
    }

    /** Statements can be emailed by someone who manages customers, to the customer's own address on file. */
    public static function canEmail(Customer $customer): bool
    {
        return app(CurrentCompany::class)->can(Ability::CustomersManage) && $customer->anonymised_at === null
            && filter_var(trim((string) $customer->email), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return array<string, string> */
    public static function branches(): array
    {
        return Branch::query()->orderBy('name')->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }

    /**
     * @param  array<string, string>  $branches
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $branches): array
    {
        return array_map(fn ($id, $name) => ['value' => (string) $id, 'label' => $name], array_keys($branches), array_values($branches));
    }
}
