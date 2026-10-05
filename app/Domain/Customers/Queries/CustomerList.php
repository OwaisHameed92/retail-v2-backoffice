<?php

namespace App\Domain\Customers\Queries;

use App\Domain\Customers\Support\MarketingConsent;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Enums\ConsentChannel;
use App\Domain\TillData\Models\Customer;
use App\Domain\TillData\Queries\TillSum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * The customers list of the current company (module 4.4), built for 100k customers per business:
 *
 * - search: exact card number, phone or email prefix, or part of the name (`(company_id, card_no|phone|email|name)`);
 * - filters: balance (owes / in credit / over the credit limit), points, current marketing consent on a channel
 *   (latest consent row per channel), a shop (customers with ledger rows there), status;
 * - balance and points are `customers.balance` / `points`, which the portal itself keeps equal to the ledger's sum
 *   (RecomputeCustomerBalances in every push; a till's cached figures never stay there). The customer page adds the
 *   ledger up again.
 */
final class CustomerList
{
    public const SORTABLE = ['name', 'balance', 'points', 'updated_at'];

    public const BALANCE_FILTERS = ['owes', 'credit', 'overLimit'];

    /** @return array<string, mixed> */
    public static function for(Request $request): array
    {
        $table = TableQuery::from($request)->sortable(self::SORTABLE)->defaultSort('name');
        $filters = self::filters($request);
        $search = $table->search();

        $query = Customer::query()
            ->when($filters['status'] !== 'everyone', fn (Builder $q) => $filters['status'] === 'active'
                ? $q->where(fn (Builder $w) => $w->where('is_active', true)->orWhereNull('is_active'))
                : $q->where('is_active', false))
            ->when($filters['balance'] === 'owes', fn (Builder $q) => $q->where('balance', '>', 0))
            ->when($filters['balance'] === 'credit', fn (Builder $q) => $q->where('balance', '<', 0))
            ->when($filters['balance'] === 'overLimit', fn (Builder $q) => $q->where('credit_limit', '>', 0)->whereColumn('balance', '>', 'credit_limit'))
            ->when($filters['points'] === 'has', fn (Builder $q) => $q->where('points', '>', 0))
            ->when($filters['consent'] !== null, fn (Builder $q) => MarketingConsent::filter($q, ConsentChannel::from((string) $filters['consent'])))
            ->when($filters['shop'] !== null, fn (Builder $q) => $q->whereExists(fn (QueryBuilder $e) => $e->from('customer_transactions as t')
                ->whereColumn('t.company_id', 'customers.company_id')->where('t.branch_id', $filters['shop'])
                ->whereColumn('t.customer_id', 'customers.id')->whereNull('t.deleted_at')))
            ->when($search !== null, fn (Builder $q) => self::search($q, (string) $search));

        $page = $table->paginate($query, fn (Customer $c) => [
            'id' => $c->id,
            'name' => $c->name ?: 'Unnamed customer',
            'cardNo' => $c->card_no ?: null,
            'phone' => $c->phone ?: null,
            'email' => $c->email ?: null,
            'tier' => $c->tier ?: null,
            'balance' => Money::normalise($c->balance ?? '0'),
            'points' => (int) $c->points,
            'creditLimit' => Money::normalise($c->credit_limit ?? '0'),
            'isActive' => $c->is_active !== false,
            'anonymised' => $c->anonymised_at !== null,
            'updatedAt' => $c->updated_at?->toIso8601ZuluString(),
        ]);

        $owing = TillSum::many(Customer::query()->where('balance', '>', 0), ['balance' => 2]);
        // A negative balance is credit the shop holds for the customer (advance, till 0.1.51).
        $credit = TillSum::many(Customer::query()->where('balance', '<', 0), ['balance' => 2]);

        return [
            'customers' => $page,
            'filters' => $filters,
            'shops' => CustomerDetail::options(CustomerDetail::branches()),
            'counts' => [
                'all' => Customer::query()->count(),
                'owing' => Customer::query()->where('balance', '>', 0)->count(),
                'owed' => $owing['balance'],
                'creditHeld' => Money::normalise(ltrim($credit['balance'], '-')),
                'points' => (int) Customer::query()->where('points', '>', 0)->sum('points'),
                'emailConsent' => Customer::query()->tap(fn (Builder $q) => MarketingConsent::filter($q, ConsentChannel::Email))->count(),
            ],
            'canEdit' => CustomerDetail::canEdit(),
        ];
    }

    /**
     * @return array{status: string, balance: string|null, points: string|null, consent: string|null, shop: string|null}
     */
    private static function filters(Request $request): array
    {
        $shop = $request->query('shop');
        $consent = ConsentChannel::tryFrom((string) $request->query('consent'));

        return [
            'status' => in_array($request->query('status'), ['active', 'inactive', 'everyone'], true) ? (string) $request->query('status') : 'active',
            'balance' => in_array($request->query('balance'), self::BALANCE_FILTERS, true) ? (string) $request->query('balance') : null,
            'points' => $request->query('points') === 'has' ? 'has' : null,
            'consent' => $consent?->value,
            'shop' => is_string($shop) && preg_match('/^[0-9A-Za-z]{26}$/', $shop) === 1 ? $shop : null,
        ];
    }

    /**
     * Exact card number, phone or email prefix, or part of the name.
     *
     * @param  Builder<Customer>  $query
     */
    private static function search(Builder $query, string $term): void
    {
        $term = trim($term);
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('card_no', strtoupper($term))
                ->orWhere('email', 'like', mb_strtolower($term).'%');

            if (strlen($digits) >= 4) {
                $q->orWhere('phone', 'like', $term.'%')
                    ->orWhereRaw("REPLACE(phone, ' ', '') like ?", ['%'.$digits.'%']);
            }
        });
    }
}
