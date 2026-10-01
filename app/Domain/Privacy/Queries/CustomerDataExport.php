<?php

namespace App\Domain\Privacy\Queries;

use App\Domain\Customers\Queries\CustomerDetail;
use App\Domain\Customers\Queries\CustomerLedger;
use App\Domain\Customers\Support\MarketingConsent;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use App\Domain\TillData\Models\CustomerOrder;
use App\Domain\TillData\Models\EReceiptLog;
use App\Domain\TillData\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything the business holds about one customer (module 7.7, a subject access request): details, the account
 * worked out from the ledger, every ledger row, loyalty points, marketing consent (now and its history), the sales
 * linked to them, customer orders under their email or phone, and e-receipts sent for their sales. Runs in the
 * company scope: another business's rows are never read.
 */
final class CustomerDataExport
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, Customer $customer): array
    {
        $branches = CustomerDetail::branches();
        $shop = fn (?string $id) => $id === null ? null : ($branches[$id] ?? 'Another shop');
        $ledger = array_map(
            fn ($row) => CustomerLedger::present($row, $branches, null),
            CustomerLedger::between($customer->id, CarbonImmutable::parse('1970-01-01'), CarbonImmutable::parse('2999-01-01')),
        );
        $sales = Sale::query()->where('customer_id', $customer->id)->orderBy('completed_at')->orderBy('id')->get();

        return [
            'generatedAt' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'business' => (string) $company->name,
            'customer' => [
                'id' => $customer->id,
                'name' => (string) $customer->name,
                'phone' => (string) $customer->phone,
                'email' => (string) $customer->email,
                'address' => (string) $customer->address,
                'dateOfBirth' => $customer->dob?->format('Y-m-d'),
                'cardNo' => (string) $customer->card_no,
                'tier' => (string) $customer->tier,
                'notes' => (string) $customer->notes,
                'creditLimit' => Money::normalise($customer->credit_limit ?? '0'),
                'isActive' => $customer->is_active !== false,
                'anonymisedAt' => $customer->anonymised_at?->toIso8601ZuluString(),
                'createdAt' => $customer->created_at?->toIso8601ZuluString(),
                'updatedAt' => $customer->updated_at?->toIso8601ZuluString(),
            ],
            'account' => CustomerLedger::totals($customer->id),
            'ledger' => $ledger,
            'loyalty' => self::loyalty($ledger),
            'consent' => [
                'current' => array_values(MarketingConsent::current($customer->id, $branches)),
                'history' => MarketingConsent::history($customer->id, $branches),
            ],
            'sales' => $sales->map(fn (Sale $s) => [
                'id' => $s->id,
                'receiptNumber' => (string) $s->receipt_number,
                'completedAt' => $s->completed_at?->toIso8601ZuluString(),
                'shop' => $shop($s->branch_id),
                'type' => $s->type?->value,
                'status' => $s->status?->value,
                'total' => Money::normalise($s->total ?? '0'),
                'vat' => Money::normalise($s->vat_total ?? '0'),
            ])->values()->all(),
            'customerOrders' => self::orders($customer)->map(fn (CustomerOrder $o) => [
                'reference' => (string) $o->reference,
                'createdAt' => $o->created_at?->toIso8601ZuluString(),
                'shop' => $shop($o->branch_id),
                'status' => $o->status?->value,
                'name' => (string) $o->customer_name,
                'phone' => (string) $o->customer_phone,
                'email' => (string) $o->customer_email,
                'goodsTotal' => Money::normalise($o->goods_total ?? '0'),
            ])->values()->all(),
            'eReceipts' => EReceiptLog::query()->whereIn('sale_id', $sales->pluck('id')->all())->orderBy('sent_at')->get()
                ->map(fn (EReceiptLog $e) => [
                    'saleId' => $e->sale_id,
                    'channel' => $e->channel?->value,
                    'address' => (string) $e->address,
                    'status' => $e->status?->value,
                    'sentAt' => $e->sent_at->toIso8601ZuluString(),
                ])->values()->all(),
        ];
    }

    /**
     * Customer orders taken at a till carry a name, phone and email, not a customer id: matched on the customer's
     * email (any case) or phone, when they have one.
     *
     * @return Builder<CustomerOrder>
     */
    public static function ordersQuery(Customer $customer): Builder
    {
        $email = mb_strtolower(trim((string) $customer->email));
        $phone = trim((string) $customer->phone);

        return CustomerOrder::query()->where(function (Builder $q) use ($email, $phone) {
            $q->whereRaw('1 = 0')
                ->when($email !== '', fn (Builder $w) => $w->orWhereRaw('LOWER(customer_email) = ?', [$email]))
                ->when($phone !== '', fn (Builder $w) => $w->orWhere('customer_phone', $phone));
        });
    }

    /**
     * @return Collection<int, CustomerOrder>
     */
    private static function orders(Customer $customer): Collection
    {
        return self::ordersQuery($customer)->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * Points earned, spent, adjusted and expired, from the ledger rows.
     *
     * @param  list<array<string, mixed>>  $ledger
     * @return array{earned: int, spent: int, adjusted: int, expired: int, balance: int}
     */
    private static function loyalty(array $ledger): array
    {
        $out = ['earned' => 0, 'spent' => 0, 'adjusted' => 0, 'expired' => 0, 'balance' => 0];

        foreach ($ledger as $row) {
            $points = (int) $row['points'];
            $key = match ($row['type']) {
                'pointsEarn' => 'earned',
                'pointsBurn' => 'spent',
                'pointsExpire' => 'expired',
                default => 'adjusted',
            };

            if ($points !== 0) {
                $out[$key] += $points;
                $out['balance'] += $points;
            }
        }

        return $out;
    }
}
