<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Data\OrderBoardFilters;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\OrderRows;
use App\Domain\OnlineStore\Support\PortalOrders;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The portal's online orders board (docs/online-store.md "Orders"): four columns for one day (shop time) and shop —
 * New, Picking, Ready (by collection time) and Done (collected or cancelled that day) — with the day's figures.
 * Today's board also keeps open orders from earlier days (marked late), so nothing waiting drops off; open orders
 * for later days are counted per day under the board.
 */
final class OnlineOrderBoard
{
    /** Cards per column; a busy shop's column says how many more there are. */
    public const COLUMN_LIMIT = 100;

    public function __construct(private readonly PortalOrders $orders, private readonly CurrentCompany $tenancy) {}

    /**
     * @return array<string, mixed>
     */
    public function for(OrderBoardFilters $filters, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $store = fn (): ?OnlineStore => OnlineStore::query()->where('company_id', $this->tenancy->require()->id)->first();

        // Closures: the 30-second refresh reloads `board` (and `store`) only.
        return [
            'store' => fn () => ($s = $store()) === null ? null : ['isLive' => $s->is_live, 'takingOrders' => $s->taking_orders],
            'setupUrl' => fn () => $store() === null ? self::builderUrl() : null,
            'filters' => $filters->toArray(),
            'options' => fn () => ['shops' => $this->shops()],
            'board' => fn () => $this->board($filters, $now),
        ];
    }

    /**
     * The part the page reloads every 30 seconds.
     *
     * @return array<string, mixed>
     */
    public function board(OrderBoardFilters $filters, CarbonImmutable $now): array
    {
        [$start, $end] = $filters->window();
        $open = $this->scoped($filters)->whereIn('status', [OnlineOrderStatus::New, OnlineOrderStatus::Picking, OnlineOrderStatus::Ready])
            ->where('collect_from', '<', $end)
            ->when(! $filters->isToday(), fn (Builder $q) => $q->where('collect_from', '>=', $start))
            ->orderBy('collect_from')->orderBy('number')->get();
        $done = $this->scoped($filters)->whereIn('status', [OnlineOrderStatus::Collected, OnlineOrderStatus::Cancelled])
            ->where(fn (Builder $q) => $q->where(fn (Builder $c) => $c->where('collected_at', '>=', $start)->where('collected_at', '<', $end))
                ->orWhere(fn (Builder $c) => $c->where('cancelled_at', '>=', $start)->where('cancelled_at', '<', $end)))
            ->get()->sortByDesc(fn (OnlineOrder $o) => ($o->collected_at ?? $o->cancelled_at)?->getTimestamp())->values();

        $columns = [];
        foreach (['new' => OnlineOrderStatus::New, 'picking' => OnlineOrderStatus::Picking, 'ready' => OnlineOrderStatus::Ready] as $key => $status) {
            $columns[$key] = $this->column($open->where('status', $status)->values(), $now);
        }
        $columns['done'] = $this->column($done, $now);

        return [
            'columns' => $columns,
            'stats' => $this->stats($filters, $start, $end, $columns['new']['count']),
            'later' => $filters->isToday() ? $this->later($filters, $end) : [],
            'refreshedAt' => $now->toIso8601ZuluString(),
        ];
    }

    /**
     * @return Builder<OnlineOrder>
     */
    private function scoped(OrderBoardFilters $filters): Builder
    {
        return $this->orders->query()->with(['lines:id,online_order_id,position,name,quantity,is_weighed', 'branch:id,name'])
            ->when($filters->shop, fn (Builder $q, string $shop) => $q->where('online_orders.branch_id', $shop));
    }

    /**
     * @param  Collection<int, OnlineOrder>|\Illuminate\Support\Collection<int, OnlineOrder>  $orders
     * @return array{count: int, cards: list<array<string, mixed>>}
     */
    private function column(Collection|\Illuminate\Support\Collection $orders, CarbonImmutable $now): array
    {
        return [
            'count' => $orders->count(),
            'cards' => $orders->take(self::COLUMN_LIMIT)->map(fn (OnlineOrder $o) => OrderRows::card($o, $now))->values()->all(),
        ];
    }

    /**
     * Orders to collect that day (not cancelled), their value and average, and how many wait to be started.
     *
     * @return array{orders: int, value: string, average: string|null, waiting: int}
     */
    private function stats(OrderBoardFilters $filters, CarbonImmutable $start, CarbonImmutable $end, int $waiting): array
    {
        $totals = $this->orders->query()->when($filters->shop, fn (Builder $q, string $shop) => $q->where('branch_id', $shop))
            ->where('status', '!=', OnlineOrderStatus::Cancelled)->where('collect_from', '>=', $start)->where('collect_from', '<', $end)
            ->pluck('items_total');
        $value = Money::sum($totals->all());
        $count = $totals->count();

        return [
            'orders' => $count,
            'value' => $value,
            'average' => $count === 0 ? null : Money::round(bcdiv($value, (string) $count, 6)),
            'waiting' => $waiting,
        ];
    }

    /**
     * Open orders for the coming days (shop time), for the "Later" chips under today's board.
     *
     * @return list<array{day: string, count: int}>
     */
    private function later(OrderBoardFilters $filters, CarbonImmutable $end): array
    {
        $zone = OnlineStoreSwitch::timezone();

        return $this->orders->query()->when($filters->shop, fn (Builder $q, string $shop) => $q->where('branch_id', $shop))
            ->whereIn('status', [OnlineOrderStatus::New, OnlineOrderStatus::Picking, OnlineOrderStatus::Ready])
            ->where('collect_from', '>=', $end)->orderBy('collect_from')->limit(1000)->pluck('collect_from')
            ->countBy(fn (CarbonImmutable $from) => $from->setTimezone($zone)->toDateString())
            ->take(7)->map(fn (int $count, string $day) => ['day' => $day, 'count' => $count])->values()->all();
    }

    /**
     * The collection shops for the shop filter (a one-shop user: theirs only).
     *
     * @return list<array{value: string, label: string}>
     */
    private function shops(): array
    {
        $restricted = $this->tenancy->restrictedBranchId();

        return OnlineStoreShop::query()->where('online_store_shops.company_id', $this->tenancy->require()->id)
            ->join('branches', 'branches.id', '=', 'online_store_shops.branch_id')
            ->when($restricted, fn (Builder $q, string $id) => $q->where('online_store_shops.branch_id', $id))
            ->orderBy('branches.name')->get(['online_store_shops.branch_id', 'branches.name'])
            ->map(fn (OnlineStoreShop $s) => ['value' => (string) $s->branch_id, 'label' => (string) $s->getAttribute('name')])->values()->all();
    }

    /** The store builder (OS-3), when it is installed. */
    private static function builderUrl(): ?string
    {
        foreach (['app.online-store.index', 'app.online-store.edit', 'app.online-store'] as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return null;
    }
}
