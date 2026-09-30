<?php

namespace App\Domain\Customers\Queries;

use App\Domain\Customers\Support\CustomerFormat;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\CustomerTransaction;
use App\Domain\TillData\Queries\TillSum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * A customer's account worked out from their ledger (`CustomerTransaction`, contract §10.1): balance = the sum of
 * `amount`, points = the sum of `points`, soft-deleted rows left out. Never the till's cached `Customer.balance` /
 * `points`, and never a row's `balanceAfter` / `pointsAfter` (a till's own running figure, which differs across
 * shops): running balances here are the portal's own, from every shop's rows in (`at`, `id`) order.
 *
 * Runs in the company scope: another business's ledger is never read.
 */
final class CustomerLedger
{
    /**
     * @return array{balance: string, points: int}
     */
    public static function totals(string $customerId, ?CarbonInterface $before = null): array
    {
        $sums = TillSum::many(
            self::rows($customerId)->when($before !== null, fn (Builder $q) => $q->where('at', '<', self::utc($before))),
            ['amount' => 2, 'points' => 0],
        );

        return ['balance' => Money::normalise($sums['amount']), 'points' => (int) $sums['points']];
    }

    /**
     * One page of the ledger, newest first, each row with the portal's running balance and points after it.
     *
     * @param  array<string, string>  $branches  branch id => name
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function page(Request $request, string $customerId, array $branches, ?string $branchId, ?string $type): array
    {
        $table = TableQuery::from($request)->defaultPerPage(25);
        $query = self::rows($customerId)
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when($type !== null, fn (Builder $q) => $q->whereIn('type', CustomerFormat::typeGroup($type)))
            ->orderByDesc('at')->orderByDesc('id');
        $paginator = $query->paginate($table->perPage(), ['*'], 'page', $table->page());

        /** @var list<CustomerTransaction> $rows */
        $rows = $paginator->items();
        $running = null;

        if ($rows !== [] && $branchId === null && $type === null) {
            $top = $rows[0];
            $newer = TillSum::many(self::rows($customerId)->where(fn (Builder $q) => $q
                ->where('at', '>', self::utc($top->at))
                ->orWhere(fn (Builder $w) => $w->where('at', self::utc($top->at))->where('id', '>', $top->id))), ['amount' => 2, 'points' => 0]);
            $all = self::totals($customerId);
            $running = ['balance' => Money::sub($all['balance'], $newer['amount']), 'points' => $all['points'] - (int) $newer['points']];
        }

        $data = [];

        foreach ($rows as $row) {
            $data[] = self::present($row, $branches, $running);

            if ($running !== null) {
                $running = ['balance' => Money::sub($running['balance'], $row->amount), 'points' => $running['points'] - (int) $row->points];
            }
        }

        return [
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(), 'perPage' => $paginator->perPage(), 'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(), 'search' => null, 'sort' => null, 'direction' => 'desc',
            ],
        ];
    }

    /**
     * Rows between two instants (from inclusive, to exclusive), oldest first.
     *
     * @return list<CustomerTransaction>
     */
    public static function between(string $customerId, CarbonInterface $from, CarbonInterface $to): array
    {
        return array_values(self::rows($customerId)
            ->where('at', '>=', self::utc($from))->where('at', '<', self::utc($to))
            ->orderBy('at')->orderBy('id')->get()->all());
    }

    /**
     * Per shop: how much each shop's rows moved the balance and points, and when last.
     *
     * @param  array<string, string>  $branches
     * @return list<array{branch: string, balance: string, points: int, count: int, lastAt: string|null}>
     */
    public static function byShop(string $customerId, array $branches): array
    {
        return self::rows($customerId)->toBase()->reorder()
            ->selectRaw('branch_id, SUM(ROUND(amount * 100)) as pence, SUM(points) as points, COUNT(*) as n, MAX(at) as last_at')
            ->groupBy('branch_id')->get()
            ->map(fn (object $row) => [
                'branch' => $branches[(string) $row->branch_id] ?? 'Another shop',
                'balance' => TillSum::fromUnits($row->pence, 2),
                'points' => (int) $row->points,
                'count' => (int) $row->n,
                'lastAt' => $row->last_at === null ? null : CustomerFormat::iso((string) $row->last_at),
            ])->sortByDesc('count')->values()->all();
    }

    /**
     * @param  array<string, string>  $branches
     * @param  array{balance: string, points: int}|null  $after
     * @return array<string, mixed>
     */
    public static function present(CustomerTransaction $row, array $branches, ?array $after): array
    {
        return [
            'id' => $row->id,
            'at' => $row->at->toIso8601ZuluString(),
            'type' => $row->type?->value,
            'typeLabel' => CustomerFormat::typeLabel($row->type),
            'shop' => $branches[(string) $row->branch_id] ?? 'Another shop',
            'note' => $row->note ?: null,
            'saleId' => $row->sale_id ?: null,
            'amount' => Money::normalise($row->amount ?? '0'),
            'points' => (int) $row->points,
            'balanceAfter' => $after['balance'] ?? null,
            'pointsAfter' => $after['points'] ?? null,
        ];
    }

    /**
     * @return Builder<CustomerTransaction>
     */
    private static function rows(string $customerId): Builder
    {
        return CustomerTransaction::query()->where('customer_id', $customerId);
    }

    private static function utc(CarbonInterface $at): string
    {
        return $at->copy()->utc()->format('Y-m-d H:i:s');
    }
}
