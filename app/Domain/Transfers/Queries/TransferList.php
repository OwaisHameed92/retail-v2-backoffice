<?php

namespace App\Domain\Transfers\Queries;

use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\StockTransfer;
use App\Domain\Transfers\Data\TransferFilters;
use App\Domain\Transfers\Support\TransferFigures;
use App\Domain\Transfers\Support\TransferState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The stock transfers list (module 5.3): every transfer between the business's shops, read only (the shops own them),
 * with where it is, whether the receiving shop's till has pulled it, and any discrepancy. A one-shop user sees only
 * transfers from or to their shop.
 */
final class TransferList
{
    private const SORTABLE = ['requested_at', 'dispatched_at', 'reference', 'dispatched_cost'];

    /** @return array<string, mixed> */
    public static function for(Request $request): array
    {
        $filters = TransferFilters::from($request);
        $table = TableQuery::from($request)->defaultPerPage(25)->sortable(self::SORTABLE)->defaultSort('requested_at', 'desc');
        $query = self::filtered($filters);
        $search = $table->search();

        if ($search !== null) {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $q) => $q->where('stock_transfers.reference', 'like', $like)->orWhere('stock_transfers.note', 'like', $like));
        }

        $paginator = $table->paginator($query);
        /** @var list<StockTransfer> $items */
        $items = array_values($paginator->items());

        return [
            'filters' => $filters->toArray(),
            'stats' => self::stats($filters),
            'rows' => [
                'data' => self::rows($items, $filters->shop),
                'meta' => [
                    'page' => $paginator->currentPage(), 'perPage' => $paginator->perPage(), 'total' => $paginator->total(),
                    'lastPage' => $paginator->lastPage(), 'search' => $search, 'sort' => $table->sort(), 'direction' => $table->direction(),
                ],
            ],
            ...self::shared(),
        ];
    }

    /** @return Builder<StockTransfer> every filter, the status included */
    public static function filtered(TransferFilters $filters): Builder
    {
        $query = TransferState::scope(TransferState::query(), $filters);

        return $filters->status === null ? $query : TransferState::whereState($query, $filters->status);
    }

    /**
     * Shops and whether the user is pinned to one, shared by the transfer screens.
     *
     * @return array{shops: list<array{id: string, name: string, code: string}>, oneShop: bool, statuses: list<string>}
     */
    public static function shared(): array
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return [
            'shops' => Branch::query()->when($restricted !== null, fn ($q) => $q->whereKey($restricted))->orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->values()->all(),
            'oneShop' => $restricted !== null,
            'statuses' => TransferState::STATES,
        ];
    }

    /** @return array<string, string> shop id → name, deleted shops included */
    public static function shopNames(): array
    {
        return Branch::query()->withTrashed()->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }

    /**
     * @param  list<StockTransfer>  $items  loaded by TransferState::query() (with `state` and `relay`)
     * @return list<array<string, mixed>>
     */
    public static function rows(array $items, ?string $shop): array
    {
        $ids = array_map(fn (StockTransfer $t) => $t->id, $items);
        $companyId = (string) app(CurrentCompany::class)->id();
        $figures = $ids === [] ? [] : TransferFigures::perTransfer($ids, $companyId);
        $received = $ids === [] ? [] : DB::table('stock_transfer_receipts')->where('company_id', $companyId)->whereIn('transfer_id', $ids)
            ->whereNull('deleted_at')->groupBy('transfer_id')->selectRaw('transfer_id, max(received_at) as at')->pluck('at', 'transfer_id')->all();
        $lines = $ids === [] ? [] : DB::table('stock_transfer_lines')->where('company_id', $companyId)->whereIn('transfer_id', $ids)
            ->whereNull('deleted_at')->groupBy('transfer_id')->selectRaw('transfer_id, count(*) as n')->pluck('n', 'transfer_id')->all();
        $names = self::shopNames();

        return array_map(function (StockTransfer $t) use ($figures, $received, $lines, $names, $shop) {
            $f = $figures[$t->id] ?? null;
            $at = $received[$t->id] ?? null;

            return [
                'id' => $t->id,
                'reference' => $t->reference,
                'from' => $names[$t->from_branch_id] ?? 'Unknown shop',
                'fromId' => $t->from_branch_id,
                'to' => $names[$t->to_branch_id] ?? 'Unknown shop',
                'toId' => $t->to_branch_id,
                'direction' => $shop === null ? null : ($t->from_branch_id === $shop ? 'out' : 'in'),
                'status' => (string) $t->getAttribute('state'),
                'relay' => (string) $t->getAttribute('relay'),
                'isReturn' => (bool) $t->is_return,
                'requestedAt' => self::iso($t->getAttribute('requested_at')),
                'dispatchedAt' => self::iso($t->getAttribute('dispatched_at')),
                'receivedAt' => is_string($at) ? self::utc($at) : null,
                'lines' => (int) ($lines[$t->id] ?? $t->line_count),
                'value' => Money::normalise($t->dispatched_cost ?? 0),
                'varianceCost' => $f['varianceCost'] ?? null,   // the till's: sent − received, positive = lost
                'discrepancies' => $f['discrepancies'] ?? 0,
            ];
        }, $items);
    }

    /**
     * Summary cards for the shop / flow / day filters (not the status).
     *
     * @return list<array{label: string, value: string, format: string, tone: string, hint?: string}>
     */
    private static function stats(TransferFilters $filters): array
    {
        $scoped = fn () => TransferState::scope(StockTransfer::query(), $filters);
        $counts = TransferState::scope(StockTransfer::query(), $filters)
            ->selectRaw(TransferState::stateSql().' as state, count(*) as n')->groupBy('state')->toBase()->pluck('n', 'state')->all();
        $waiting = (int) $scoped()->whereRaw('('.TransferState::relaySql().") = 'waiting'")->count();
        $moving = ($counts['dispatched'] ?? 0) + ($counts['inTransit'] ?? 0);
        $movingValue = Money::normalise($scoped()->whereRaw('('.TransferState::stateSql().") in ('dispatched', 'inTransit')")->sum('dispatched_cost') ?: 0);
        $figures = TransferFigures::perTransfer(null, (string) app(CurrentCompany::class)->id(), fn ($q) => $q->whereIn('rl.transfer_id', $scoped()->select('stock_transfers.id')->toBase()));
        $lost = Money::sum(array_column($figures, 'varianceCost'));
        $withDiscrepancy = count(array_filter($figures, fn ($f) => $f['discrepancies'] > 0));

        return [
            self::stat('On the way', (string) $moving, 'count', 'primary', self::pounds($movingValue).' at cost'),
            self::stat('Not pulled yet', (string) $waiting, 'count', $waiting > 0 ? 'warning' : 'neutral', "Dispatched, not yet on the receiving shop's till"),
            self::stat('Received', (string) (($counts['received'] ?? 0) + ($counts['partlyReceived'] ?? 0)), 'count', 'success', ($counts['partlyReceived'] ?? 0).' partly received'),
            self::stat('Lost in transit at cost', $lost, 'money', Money::compare($lost, '0') > 0 ? 'danger' : 'neutral', $withDiscrepancy.' '.($withDiscrepancy === 1 ? 'transfer' : 'transfers').' with differences'),
        ];
    }

    /** @return array{label: string, value: string, format: string, tone: string, hint?: string} */
    private static function stat(string $label, string $value, string $format, string $tone, ?string $hint = null): array
    {
        return ['label' => $label, 'value' => $value, 'format' => $format, 'tone' => $tone, ...($hint === null ? [] : ['hint' => $hint])];
    }

    /** An ISO UTC time from a cast date (the till's date columns may be empty). */
    public static function iso(mixed $at): ?string
    {
        return $at instanceof CarbonInterface ? $at->toIso8601ZuluString() : null;
    }

    private static function utc(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->toIso8601ZuluString();
    }

    /** "£1,234.50" from a 2 dp money string, without going through a float. */
    public static function pounds(string $money): string
    {
        [$whole, $pence] = explode('.', ltrim($money, '-')) + [1 => '00'];

        return (str_starts_with($money, '-') ? '-' : '').'£'.strrev(implode(',', str_split(strrev($whole), 3))).'.'.$pence;
    }
}
