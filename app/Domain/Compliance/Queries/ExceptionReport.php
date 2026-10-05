<?php

namespace App\Domain\Compliance\Queries;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\ExceptionLog;
use App\Domain\TillData\Models\TillAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Exceptions per staff member, the loss-prevention view (module 5.7): drawer opened with no sale (`ExceptionLog` type
 * `NoSale`), lines voided from an open basket (`AuditLog` action `LineVoided`, PORTAL-CHANGES-0.1.15 item 16; on
 * every void since till 0.1.28, reason "Not asked" when the shop does not ask) and every other exception the till logs (price overrides, refunds…), with
 * their amounts. The log shows the exceptions, or the voided lines when `type` is `LineVoided`.
 */
final class ExceptionReport
{
    public const NO_SALE = 'NoSale';

    public const LINE_VOIDED = 'LineVoided';

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, ComplianceFilters $f): array
    {
        $byStaff = self::exceptions($f, false)->groupBy('user_id')->select([
            'user_id', DB::raw('count(*) as n'), DB::raw("sum(case when lower(type) = 'nosale' then 1 else 0 end) as no_sale"), DB::raw('sum(amount) as amount'),
        ])->toBase()->get()->keyBy(fn ($r) => (string) $r->user_id);
        $voids = self::voids($f)->groupBy('user_id')->select(['user_id', DB::raw('count(*) as n')])->toBase()->pluck('n', 'user_id');
        $ids = array_values(array_unique([...$byStaff->keys()->all(), ...array_map('strval', $voids->keys()->all())]));
        $names = L::staff($ids);

        $staff = collect($ids)->map(fn (string $id) => [
            'key' => $id !== '' ? $id : null,
            'staff' => L::name($names, $id) ?? 'Unknown staff',
            'noSales' => (int) ($byStaff[$id]->no_sale ?? 0),
            'voidedLines' => (int) ($voids[$id] ?? 0),
            'other' => (int) ($byStaff[$id]->n ?? 0) - (int) ($byStaff[$id]->no_sale ?? 0),
            'amount' => Money::normalise($byStaff[$id]->amount ?? 0),
        ])->map(fn (array $r) => [...$r, 'total' => $r['noSales'] + $r['voidedLines'] + $r['other']])
            ->sortByDesc('total')->values();

        $types = self::exceptions($f, false)->groupBy('type')->select(['type', DB::raw('count(*) as n'), DB::raw('sum(amount) as amount')])
            ->orderByDesc('n')->toBase()->get()
            ->map(fn ($t) => ['value' => (string) $t->type, 'label' => self::typeLabel((string) $t->type), 'count' => (int) $t->n, 'amount' => Money::normalise($t->amount ?? 0)])->values()->all();

        return [
            'staff' => $staff->all(),
            'types' => $types,
            'summary' => [
                'noSales' => (int) $staff->sum('noSales'),
                'voidedLines' => (int) $staff->sum('voidedLines'),
                'other' => (int) $staff->sum('other'),
                'amount' => Money::sum($staff->pluck('amount')),
            ],
            'log' => $f->type === self::LINE_VOIDED ? self::voidLog($request, $f) : self::exceptionLog($request, $f),
        ];
    }

    /** Types whose words are not just the split name (PORTAL-CHANGES-2026-10-02-cash-reports §1, till 0.1.28). */
    private const LABELS = ['CartCleared' => 'Sale cleared before payment', 'HeldSaleDiscarded' => 'Held sale thrown away'];

    /** Words for a till exception type ("NoSale" → "No sale", "PriceOverride" → "Price override"). */
    public static function typeLabel(string $type): string
    {
        if ($type === '') {
            return 'Other';
        }

        if (isset(self::LABELS[$type])) {
            return self::LABELS[$type];
        }

        return ucfirst(strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $type))));
    }

    /**
     * @return array<string, mixed>
     */
    private static function exceptionLog(Request $request, ComplianceFilters $f): array
    {
        $table = TableQuery::from($request)->sortable(['at', 'amount'])->defaultSort('at', 'desc')->defaultPerPage(25);
        $page = $table->paginator(self::exceptions($f));
        /** @var list<ExceptionLog> $rows */
        $rows = $page->items();
        [$shops, $tills, $staff] = self::names($rows);

        return [
            'kind' => 'exceptions',
            'data' => array_map(fn (ExceptionLog $r) => [
                'id' => $r->id, 'at' => L::iso($r->at), 'type' => self::typeLabel((string) $r->type), 'staff' => L::name($staff, $r->user_id),
                'shop' => L::name($shops, $r->branch_id), 'till' => L::name($tills, $r->register_id), 'amount' => Money::normalise($r->amount),
                'detail' => self::change(L::blank($r->before_value), L::blank($r->after_value)),
            ], $rows),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => null, 'sort' => $table->sort(), 'direction' => $table->direction()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function voidLog(Request $request, ComplianceFilters $f): array
    {
        $table = TableQuery::from($request)->sortable(['at'])->defaultSort('at', 'desc')->defaultPerPage(25);
        $page = $table->paginator(self::voids($f));
        /** @var list<TillAuditLog> $rows */
        $rows = $page->items();
        [$shops, $tills, $staff] = self::names($rows);

        return [
            'kind' => 'voids',
            'data' => array_map(function (TillAuditLog $r) use ($shops, $tills, $staff) {
                $after = json_decode((string) $r->after_json, true);
                $after = is_array($after) ? $after : [];
                $pick = fn (array $keys) => collect($keys)->map(fn ($k) => $after[$k] ?? null)->first(fn ($v) => is_scalar($v) && (string) $v !== '');

                return [
                    'id' => $r->id, 'at' => L::iso($r->at), 'type' => 'Line voided', 'staff' => L::name($staff, $r->user_id),
                    'shop' => L::name($shops, $r->branch_id), 'till' => L::name($tills, $r->register_id),
                    // The till writes PascalCase members (Value, Quantity, ProductName; till 0.1.28).
                    'amount' => is_numeric($v = $pick(['Value', 'value', 'lineTotal', 'total', 'amount'])) ? Money::normalise($v) : null,
                    'detail' => trim(implode(' × ', array_filter([(string) $pick(['Quantity', 'quantity', 'qty']), (string) $pick(['ProductName', 'product', 'productName', 'name'])])).($r->reason ? ' · '.$r->reason : '')) ?: null,
                ];
            }, $rows),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => null, 'sort' => $table->sort(), 'direction' => $table->direction()],
        ];
    }

    /**
     * @param  list<ExceptionLog>|list<TillAuditLog>  $rows
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>}
     */
    private static function names(array $rows): array
    {
        $col = fn (string $c) => array_map(fn ($r) => $r->getAttribute($c), $rows);

        return [L::shops($col('branch_id')), L::tills($col('register_id')), L::staff($col('user_id'))];
    }

    private static function change(?string $before, ?string $after): ?string
    {
        return match (true) {
            $before !== null && $after !== null => "{$before} → {$after}",
            default => $after ?? $before,
        };
    }

    /** @return Builder<ExceptionLog> */
    private static function exceptions(ComplianceFilters $f, bool $byType = true): Builder
    {
        // Till 0.1.28 also logs every line void as an ExceptionLog `LineVoided`: voids are counted once, from the
        // AuditLog rows (voids()), so those exception rows are left out here.
        return $f->during($f->scope(ExceptionLog::query()), 'at')
            ->where(fn (Builder $q) => $q->where('type', '!=', self::LINE_VOIDED)->orWhereNull('type'))
            ->when($f->staff !== null, fn (Builder $q) => $q->where('user_id', $f->staff))
            ->when($byType && $f->type !== null && $f->type !== self::LINE_VOIDED, fn (Builder $q) => $q->where('type', $f->type));
    }

    /** @return Builder<TillAuditLog> */
    private static function voids(ComplianceFilters $f): Builder
    {
        return $f->during($f->scope(TillAuditLog::query()), 'at')->where('action', self::LINE_VOIDED)
            ->when($f->staff !== null, fn (Builder $q) => $q->where('user_id', $f->staff));
    }
}
