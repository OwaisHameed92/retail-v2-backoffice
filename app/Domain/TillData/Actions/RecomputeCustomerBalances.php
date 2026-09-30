<?php

namespace App\Domain\TillData\Actions;

use App\Domain\TillData\Queries\TillSum;
use App\Domain\TillData\Sync\CompanyRows;
use App\Domain\TillData\Sync\Data\MappedChange;
use Illuminate\Support\Facades\DB;

/**
 * A customer's balance and points are the sum of their ledger (contract v1.4 §10.1): `balance` = the sum of
 * `CustomerTransaction.amount`, `points` = the sum of `points`, leaving out soft-deleted rows. `Customer.balance` /
 * `points` are only a cache: a till's pushed figures are never the truth (ApplySyncChanges calls afterPush() in the
 * same transaction, so the stored row always shows the ledger's sum), and the pull sends ours (the till ignores it).
 *
 * The cache is written with the query builder: no `hub_edited_at`, no new pull version (a balance alone is not a
 * change of the customer row; it is left out of its content hash, OwnershipRules::DERIVED_COLUMNS).
 *
 *     app(RecomputeCustomerBalances::class)->handle($companyId);              // every customer of the company
 *     app(RecomputeCustomerBalances::class)->handle($companyId, [$customerId]);
 */
final class RecomputeCustomerBalances
{
    private const CHUNK = 500;

    /**
     * @param  list<string>|null  $customerIds  null = every customer of the company
     * @return int customers whose cache changed
     */
    public function handle(string $companyId, ?array $customerIds = null): int
    {
        $customerIds ??= DB::table('customers')->where('company_id', $companyId)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $changed = 0;

        foreach (array_chunk(array_values(array_unique($customerIds)), self::CHUNK) as $chunk) {
            $sums = DB::table('customer_transactions')
                ->where('company_id', $companyId)
                ->whereIn('customer_id', $chunk)
                ->whereNull('deleted_at')
                ->groupBy('customer_id')
                ->select('customer_id', DB::raw('SUM(ROUND(amount * 100)) as pence'), DB::raw('SUM(points) as points'))
                ->get()
                ->keyBy('customer_id');

            $current = CompanyRows::whereIn('customers', $companyId, 'id', $chunk, ['id', 'balance', 'points']);

            foreach ($current as $customer) {
                $sum = $sums[$customer->id] ?? null;
                $balance = TillSum::fromUnits($sum?->pence, 2);
                $points = (int) ($sum->points ?? 0);

                if (bccomp((string) ($customer->balance ?? '0'), $balance, 2) === 0 && (int) $customer->points === $points) {
                    continue;
                }

                DB::table('customers')->where('company_id', $companyId)->where('id', $customer->id)->update(['balance' => $balance, 'points' => $points]);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * After a push chunk is written: the customers of every Customer and CustomerTransaction change in it (a till's
     * cached figures on a Customer row are replaced by the ledger's sum).
     *
     * @param  list<MappedChange>  $changes
     */
    public function afterPush(string $companyId, array $changes): void
    {
        $customers = [];
        $ledgerIds = [];

        foreach ($changes as $mapped) {
            if ($mapped->change->entity === 'Customer') {
                $customers[] = $mapped->change->entityId;
            } elseif ($mapped->change->entity === 'CustomerTransaction') {
                $ledgerIds[] = $mapped->change->entityId;
            }
        }

        foreach (CompanyRows::whereIn('customer_transactions', $companyId, 'id', $ledgerIds, ['customer_id'], fn ($query) => $query->whereNotNull('customer_id')) as $row) {
            $customers[] = (string) $row->customer_id;
        }

        if ($customers !== []) {
            $this->handle($companyId, $customers);
        }
    }
}
