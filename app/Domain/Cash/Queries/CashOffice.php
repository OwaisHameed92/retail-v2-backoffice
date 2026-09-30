<?php

namespace App\Domain\Cash\Queries;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Enums\CashOfficeBankingStatus;
use App\Domain\TillData\Models\CashOfficeBanking;
use App\Domain\TillData\Models\CashOfficeReconciliation;
use App\Domain\TillData\Models\Reason;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The cash office (module 5.4), read only: bankings prepared in the chosen days (status, own banking or carrier,
 * who collected, confirmed amount and variance, as the till sent them) and the safe / cash office counts
 * (`CashOfficeReconciliation`: expected against counted per trading day). Shop-level rows: the till filter does
 * not apply.
 */
final class CashOffice
{
    /**
     * @return array<string, mixed>
     */
    public static function banking(Request $request, CashFilters $filters): array
    {
        $table = TableQuery::from($request)->sortable(['prepared_at', 'amount'])->defaultSort('prepared_at', 'desc')->defaultPerPage(25);
        $base = fn () => $filters->during($filters->scope(CashOfficeBanking::query(), false), 'prepared_at');
        $page = $table->paginator($base());
        /** @var list<CashOfficeBanking> $rows */
        $rows = $page->items();
        $shops = CashLookup::shops(array_map(fn (CashOfficeBanking $b) => $b->branch_id, $rows));
        $staff = CashLookup::staff(array_merge(...array_map(fn (CashOfficeBanking $b) => [$b->prepared_by_user_id, $b->banked_by_user_id, $b->collected_by_user_id], $rows)));
        $live = $base()->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', CashOfficeBankingStatus::Cancelled->value));

        return [
            'bankings' => [
                'data' => array_map(fn (CashOfficeBanking $b) => [
                    'id' => $b->id,
                    'reference' => $b->reference !== '' ? $b->reference : null,
                    'shop' => CashLookup::name($shops, $b->branch_id),
                    'amount' => CashLookup::money($b->amount),
                    'status' => $b->status?->value,
                    'method' => $b->collection_method?->value,
                    'carrier' => $b->carrier_name !== '' ? $b->carrier_name : null,
                    'sealNumber' => $b->seal_number !== '' ? $b->seal_number : null,
                    'preparedAt' => CashLookup::iso($b->prepared_at),
                    'preparedBy' => CashLookup::name($staff, $b->prepared_by_user_id),
                    'collectedAt' => CashLookup::iso($b->collected_at),
                    'collectedBy' => CashLookup::name($staff, $b->collected_by_user_id) ?? ($b->collected_signature_name !== '' ? $b->collected_signature_name : null),
                    'bankedAt' => CashLookup::iso($b->banked_at),
                    'bankedBy' => CashLookup::name($staff, $b->banked_by_user_id),
                    'bankReference' => $b->bank_reference !== '' ? $b->bank_reference : null,
                    'confirmedAmount' => CashLookup::money($b->confirmed_amount),
                    'variance' => CashLookup::money($b->variance_amount),
                    'note' => $b->note !== '' ? $b->note : null,
                ], $rows),
                'meta' => self::meta($page, $table),
            ],
            'summary' => [
                'count' => (clone $live)->count(),
                'total' => Money::sum((clone $live)->pluck('amount')),
                'waiting' => (clone $live)->whereIn('status', [CashOfficeBankingStatus::Prepared->value, CashOfficeBankingStatus::InTransit->value])->count(),
                'variance' => Money::sum((clone $live)->whereNotNull('variance_amount')->pluck('variance_amount')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function counts(Request $request, CashFilters $filters): array
    {
        $table = TableQuery::from($request)->sortable(['trading_date', 'counted_at', 'variance'])->defaultSort('counted_at', 'desc')->defaultPerPage(25);
        $base = fn () => $filters->onDays($filters->scope(CashOfficeReconciliation::query(), false));
        $page = $table->paginator($base());
        /** @var list<CashOfficeReconciliation> $rows */
        $rows = $page->items();
        $shops = CashLookup::shops(array_map(fn (CashOfficeReconciliation $c) => $c->branch_id, $rows));
        $staff = CashLookup::staff(array_map(fn (CashOfficeReconciliation $c) => $c->counted_by_user_id, $rows));
        $reasons = Reason::query()->withTrashed()->whereKey(array_values(array_filter(array_map(fn (CashOfficeReconciliation $c) => $c->reason_id, $rows))))->pluck('text', 'id');

        return [
            'counts' => [
                'data' => array_map(fn (CashOfficeReconciliation $c) => [
                    'id' => $c->id,
                    'day' => $c->trading_date->format('Y-m-d'),
                    'shop' => CashLookup::name($shops, $c->branch_id),
                    'countedAt' => CashLookup::iso($c->counted_at),
                    'countedBy' => CashLookup::name($staff, $c->counted_by_user_id),
                    'expected' => CashLookup::money($c->expected_balance),
                    'counted' => CashLookup::money($c->counted_balance),
                    'variance' => CashLookup::money($c->variance),
                    'reason' => $c->reason_id !== '' ? ($reasons[$c->reason_id] ?? null) : null,
                    'note' => $c->note !== '' ? $c->note : null,
                    'denominations' => self::denominations($c->denominations_json),
                ], $rows),
                'meta' => self::meta($page, $table),
            ],
            'summary' => [
                'count' => $base()->count(),
                'variance' => Money::sum($base()->pluck('variance')),
                'short' => $base()->where('variance', '<', 0)->count(),
                'over' => $base()->where('variance', '>', 0)->count(),
            ],
        ];
    }

    /**
     * The till's denominations JSON as sent (`[{denomination|value, count, total?}]` or `{"20.00": 3}`), shown as lines.
     *
     * @return list<array{denomination: string, count: int}>
     */
    private static function denominations(?string $json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        $out = [];

        foreach (is_array($data) ? $data : [] as $key => $value) {
            if (is_array($value)) {
                $denomination = $value['denomination'] ?? $value['Denomination'] ?? $value['value'] ?? $value['Value'] ?? null;
                $count = $value['count'] ?? $value['Count'] ?? $value['quantity'] ?? $value['Quantity'] ?? null;
            } else {
                [$denomination, $count] = [$key, $value];
            }

            if (is_numeric($denomination) && is_numeric($count)) {
                $out[] = ['denomination' => Money::normalise($denomination), 'count' => (int) $count];
            }
        }

        return $out;
    }

    /**
     * @template TModel of Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $page
     * @return array<string, mixed>
     */
    public static function meta(LengthAwarePaginator $page, TableQuery $table): array
    {
        return ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => null, 'sort' => $table->sort(), 'direction' => $table->direction()];
    }
}
