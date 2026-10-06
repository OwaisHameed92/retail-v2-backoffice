<?php

namespace App\Domain\Compliance\Support;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\ProductRecallStatus;
use App\Domain\TillData\Models\ProductRecall;
use App\Domain\TillData\Models\ProductRecallBranchState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A recall's state per shop (till 0.1.52, contract §10.1): the recall is company-wide, each shop closes and reopens it
 * for itself (`ProductRecallBranchState`, branch-owned; no row = open in that shop). The company row's own `status`
 * is a derived column and is never read. A recall is open while at least one of the shops counted has not closed it,
 * closed once every one has. Counted shops: the one picked, else every active shop.
 */
final class RecallShops
{
    /**
     * @return list<string> the shops a recall's state is counted over
     */
    public static function ids(?string $shop): array
    {
        if ($shop !== null) {
            return [$shop];
        }

        return Branch::query()->active()->orderBy('name')->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * Recalls still open in at least one of the shops (`$open`), or closed in all of them.
     *
     * @param  Builder<ProductRecall>  $query
     * @param  list<string>  $shops
     * @return Builder<ProductRecall>
     */
    public static function where(Builder $query, array $shops, bool $open): Builder
    {
        $closed = self::closedCount($shops)->toBase();

        return $query->whereRaw('('.$closed->toSql().') '.($open ? '<' : '>=').' ?', [...$closed->getBindings(), max(count($shops), 1)]);
    }

    /**
     * Open first: fewer shops closed first, so a recall still open somewhere comes before one closed everywhere.
     *
     * @param  Builder<ProductRecall>  $query
     * @param  list<string>  $shops
     * @return Builder<ProductRecall>
     */
    public static function openFirst(Builder $query, array $shops): Builder
    {
        return $query->orderBy(self::closedCount($shops));
    }

    /**
     * Shops (of those counted) that closed each recall.
     *
     * @param  list<string>  $recallIds
     * @param  list<string>  $shops
     * @return array<string, int> recall id → shops closed
     */
    public static function closed(array $recallIds, array $shops): array
    {
        if ($recallIds === [] || $shops === []) {
            return [];
        }

        return ProductRecallBranchState::query()->whereIn('recall_id', $recallIds)->whereIn('branch_id', $shops)
            ->where('status', ProductRecallStatus::Closed->value)
            ->get(['recall_id', 'branch_id'])->groupBy('recall_id')
            ->map(fn (Collection $rows) => $rows->pluck('branch_id')->unique()->count())->all();
    }

    /**
     * The list's status words: `open` while a counted shop has not closed it, and how many shops are still open.
     *
     * @return array{status: 'open'|'closed', openShops: int, shops: int}
     */
    public static function status(int $closed, int $shops): array
    {
        $open = max($shops - $closed, 0);

        return ['status' => $shops === 0 || $open > 0 ? 'open' : 'closed', 'openShops' => $open, 'shops' => $shops];
    }

    /**
     * One recall in each shop counted (and any other shop that closed it or sent stock back against it), by name:
     * open or closed there, when and by whom it was closed, the shop's note, and what it returned.
     *
     * @param  list<string>  $shops
     * @param  Collection<string, string>  $returned  branch id → quantity
     * @return list<array{shop: string, status: 'open'|'closed', closedAt: ?string, closedBy: ?string, note: ?string, returned: ?string}>
     */
    public static function states(ProductRecall $recall, array $shops, Collection $returned): array
    {
        $states = ProductRecallBranchState::query()->where('recall_id', $recall->id)
            ->when(count($shops) === 1, fn (Builder $q) => $q->whereIn('branch_id', $shops))
            ->orderBy('updated_at')->get()->keyBy(fn (ProductRecallBranchState $s) => (string) $s->branch_id);
        $ids = collect($shops)->merge(count($shops) === 1 ? [] : [...$states->keys(), ...$returned->keys()])->unique()->values()->all();
        $names = ComplianceLookup::shops($ids);
        $staff = ComplianceLookup::staff($states->pluck('closed_by_user_id')->filter()->all());

        return collect($ids)->map(function (string $id) use ($states, $names, $staff, $returned): array {
            /** @var ProductRecallBranchState|null $state */
            $state = $states->get($id);
            $closed = $state?->status === ProductRecallStatus::Closed;

            return [
                'shop' => ComplianceLookup::name($names, $id) ?? 'Unknown shop',
                'status' => $closed ? 'closed' : 'open',
                'closedAt' => $closed ? ComplianceLookup::iso($state->closed_at) : null,
                'closedBy' => $closed ? ComplianceLookup::name($staff, ComplianceLookup::blank($state->closed_by_user_id)) : null,
                'note' => ComplianceLookup::blank($state?->note),
                'returned' => $returned[$id] ?? null,
            ];
        })->sortBy([['status', 'desc'], ['shop', 'asc']])->values()->all();
    }

    /**
     * @param  list<string>  $shops
     * @return Builder<ProductRecallBranchState>
     */
    private static function closedCount(array $shops): Builder
    {
        return ProductRecallBranchState::query()
            ->selectRaw('count(distinct branch_id)')
            ->whereColumn('product_recall_branch_states.recall_id', 'product_recalls.id')
            ->where('status', ProductRecallStatus::Closed->value)
            ->whereIn('branch_id', $shops === [] ? [''] : $shops);
    }
}
