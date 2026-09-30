<?php

namespace App\Domain\Sales\Queries;

use App\Domain\Sales\Data\SaleFilters;
use App\Domain\Sales\Models\SalesExport;
use App\Domain\Sales\Support\SaleNames;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The sales list page (module 4.6): one keyset page of the filtered sales with names, tenders and "refunded" marks,
 * a capped count, the filter options and the user's recent exports. Everything in the current company's scope; a
 * one-shop user's filters are already pinned to their shop (SaleFilters).
 */
final class SaleList
{
    public const PER_PAGE = [25, 50, 100];

    /** @return array<string, mixed> */
    public static function for(Request $request, SaleFilters $filters, ?int $userId): array
    {
        $perPage = in_array((int) $request->query('perPage'), self::PER_PAGE, true) ? (int) $request->query('perPage') : 50;
        $query = SaleSearch::query($filters);
        $page = SaleSearch::page($query, self::cursor($request, 'after'), self::cursor($request, 'before'), $perPage);
        $count = SaleSearch::countUpTo($query);

        return [
            'sales' => [
                'data' => self::rows($page['rows']),
                'older' => $page['older'],
                'newer' => $page['newer'],
                'count' => min($count, SaleSearch::COUNT_CAP),
                'capped' => $count > SaleSearch::COUNT_CAP,
                'perPage' => $perPage,
            ],
            'filters' => [...$filters->toArray(), 'shop' => $filters->shop ?? ($filters->shopLocked ? null : 'all')],
            'options' => self::options($filters),
            'exports' => SalesExport::recentFor($userId),
            'exportStreamLimit' => SalesExport::STREAM_ROWS,
            'canViewCustomers' => app(CurrentCompany::class)->can(Ability::CustomersView),
        ];
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $sales): array
    {
        $ids = $sales->pluck('id')->all();
        $names = SaleNames::for($sales);
        $tenders = self::tenders($ids);
        $refunded = $ids === [] ? [] : array_flip(Sale::query()->whereIn('original_sale_id', $ids)->where('status', 'completed')
            ->pluck('original_sale_id')->map(fn ($id) => (string) $id)->all());
        $items = $ids === [] ? [] : DB::table('sale_lines')->whereIn('sale_id', $ids)->whereNull('deleted_at')
            ->where('company_id', app(CurrentCompany::class)->id())
            ->groupBy('sale_id')->selectRaw('sale_id, COUNT(*) as n')->pluck('n', 'sale_id')->all();

        return $sales->map(fn (Sale $s) => [
            'id' => $s->id,
            'receiptNumber' => $s->receipt_number ?: '#'.$s->number,
            'type' => $s->type?->value,
            'status' => $s->status?->value,
            'day' => (string) $s->getAttribute('trading_day'),
            'at' => ($s->completed_at ?? $s->updated_at ?? $s->created_at)?->toIso8601ZuluString(),
            'shop' => $names->shop($s->branch_id),
            'till' => $names->till($s->register_id),
            'staff' => $names->person($s->user_id),
            'customer' => $names->customer($s->customer_id),
            'items' => (int) ($items[$s->id] ?? 0),
            'total' => Money::normalise($s->total ?? '0'),
            'discount' => Money::add($s->discount_total ?? '0', $s->promo_total ?? '0'),
            'tenders' => $tenders[$s->id] ?? [],
            'refunded' => isset($refunded[$s->id]),
        ])->values()->all();
    }

    /**
     * Tender names per sale, in payment order, reversed payments left out.
     *
     * @param  list<string>  $saleIds
     * @return array<string, list<string>>
     */
    private static function tenders(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        $out = [];
        $rows = DB::table('sale_payments')->where('company_id', app(CurrentCompany::class)->id())->whereIn('sale_id', $saleIds)
            ->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'reversed'))
            ->orderBy('position')->get(['sale_id', 'payment_type_name']);

        foreach ($rows as $row) {
            $name = (string) $row->payment_type_name !== '' ? (string) $row->payment_type_name : 'Payment';

            if (! in_array($name, $out[$row->sale_id] ?? [], true)) {
                $out[$row->sale_id][] = $name;
            }
        }

        return $out;
    }

    /** @return array<string, list<array<string, string|null>>> */
    public static function options(SaleFilters $filters): array
    {
        $shops = Branch::query()->when($filters->shopLocked, fn ($q) => $q->whereKey($filters->shop))->orderBy('name')->get(['id', 'name']);
        $shopNames = $shops->pluck('name', 'id');
        $tills = Register::query()->when($filters->shop !== null, fn ($q) => $q->where('branch_id', $filters->shop))
            ->whereIn('branch_id', $shops->pluck('id'))->orderBy('branch_id')->orderBy('code')->get(['id', 'branch_id', 'name', 'code']);

        return [
            'shops' => $shops->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
            'tills' => $tills->map(fn (Register $r) => [
                'value' => $r->id,
                'label' => ($r->name !== '' ? $r->name : 'Till '.$r->code).($filters->shop === null && $shops->count() > 1 ? ' · '.$shopNames[$r->branch_id] : ''),
            ])->values()->all(),
            'staff' => TillUser::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (TillUser $u) => ['value' => $u->id, 'label' => $u->name !== '' ? $u->name : 'Unnamed'])->values()->all(),
            'payments' => PaymentType::query()->orderBy('position')->pluck('name')
                ->map(fn ($n) => trim((string) $n))->filter()->unique(fn ($n) => mb_strtolower($n))
                ->map(fn ($n) => ['value' => $n, 'label' => $n])->values()->all(),
        ];
    }

    private static function cursor(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && strlen($value) <= 80 ? $value : null;
    }
}
