<?php

namespace App\Domain\Privacy\Queries;

use App\Domain\Customers\Queries\CustomerLedger;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Customers past the business's data retention (module 7.7): not anonymised, not deleted, and with no activity since
 * the cut-off: their row was not changed, they have no account or points entry and no sale since then. Runs in the
 * company scope.
 */
final class RetentionDue
{
    public static function cutoff(int $months): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->subMonthsNoOverflow($months);
    }

    /**
     * @return Builder<Customer>
     */
    public static function query(int $months): Builder
    {
        $cutoff = self::cutoff($months)->format('Y-m-d H:i:s');

        return Customer::query()
            ->whereNull('anonymised_at')
            ->where(fn (Builder $q) => $q->where('updated_at', '<', $cutoff)->orWhere(fn (Builder $w) => $w->whereNull('updated_at')->where('created_at', '<', $cutoff)))
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('customer_transactions as ct')
                ->whereColumn('ct.company_id', 'customers.company_id')->whereColumn('ct.customer_id', 'customers.id')
                ->whereNull('ct.deleted_at')->where('ct.at', '>=', $cutoff))
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('sales as s')
                ->whereColumn('s.company_id', 'customers.company_id')->whereColumn('s.customer_id', 'customers.id')
                ->where('s.completed_at', '>=', $cutoff));
    }

    /**
     * A page of due customers for the settings screen: id, name, last change and whether their account is settled.
     *
     * @return list<array{id: string, name: string, lastActivity: string|null, settled: bool}>
     */
    public static function sample(int $months, int $limit = 25): array
    {
        return self::query($months)->orderBy('updated_at')->limit($limit)->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => (string) $c->name,
                'lastActivity' => ($c->updated_at ?? $c->created_at)?->toIso8601ZuluString(),
                'settled' => Money::isZero(CustomerLedger::totals($c->id)['balance']),
            ])->values()->all();
    }
}
