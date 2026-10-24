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
use App\Domain\Tenancy\Enums\Ability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The portal's online orders board (docs/online-store.md "Orders"): four columns for one day (shop time) and shop —
 * New, Picking, Ready (by collection time) and Done (collected or cancelled that day) — with the day's figures.
 * New orders are always in the New column, whatever day they are to be collected (OS-6 fix: tomorrow's new order
 * must not wait unseen under "Coming up"); such a card says its day (`otherDay`). Picking and Ready are per day, and
 * today's board also keeps those from earlier days (marked late), so nothing waiting drops off; open orders for later
 * days are counted per day under the board.
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
            // Audit OSA-5: false once the store went off while open orders are still to finish here.
            'storeAvailable' => fn () => OnlineStoreSwitch::availableFor($this->tenancy->require()),
            // Audit OSA-32: the builder link only for users who may build the store.
            'setupUrl' => fn () => $store() === null && $this->tenancy->can(Ability::StoreManage) ? self::builderUrl() : null,
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
        $new = $this->scoped($filters)->where('status', OnlineOrderStatus::New)->orderBy('collect_from')->orderBy('number')->get();
        $open = $this->scoped($filters)->whereIn('status', [OnlineOrderStatus::Picking, OnlineOrderStatus::Ready, OnlineOrderStatus::OutForDelivery])
            ->where('collect_from', '<', $end)
            ->when(! $filters->isToday(), fn (Builder $q) => $q->where('collect_from', '>=', $start))
            ->orderBy('collect_from')->orderBy('number')->get();
        $finished = ['collected_at', 'delivered_at', 'not_collected_at', 'cancelled_at'];
        $done = $this->scoped($filters)->whereIn('status', [OnlineOrderStatus::Collected, OnlineOrderStatus::Delivered, OnlineOrderStatus::NotCollected, OnlineOrderStatus::Cancelled])
            ->where(function (Builder $q) use ($finished, $start, $end) {
                foreach ($finished as $column) {
                    $q->orWhere(fn (Builder $c) => $c->where($column, '>=', $start)->where($column, '<', $end));
                }
            })
            ->get()->sortByDesc(fn (OnlineOrder $o) => OrderRows::doneAt($o)?->getTimestamp())->values();

        // OS-9: the Ready column also holds delivery orders out for delivery.
        $columns = ['new' => $this->column($new, $now, $start, $end)];
        $columns['picking'] = $this->column($open->where('status', OnlineOrderStatus::Picking)->values(), $now);
        $columns['ready'] = $this->column($open->whereIn('status', [OnlineOrderStatus::Ready, OnlineOrderStatus::OutForDelivery])->values(), $now);
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
     * With the board's day (`$start`, `$end`), each card says whether it is to be collected on another day (`otherDay`).
     *
     * @param  Collection<int, OnlineOrder>|\Illuminate\Support\Collection<int, OnlineOrder>  $orders
     * @return array{count: int, cards: list<array<string, mixed>>}
     */
    private function column(Collection|\Illuminate\Support\Collection $orders, CarbonImmutable $now, ?CarbonImmutable $start = null, ?CarbonImmutable $end = null): array
    {
        $otherDay = fn (OnlineOrder $o) => $start !== null && $end !== null && ($o->collect_from->lessThan($start) || $o->collect_from->greaterThanOrEqualTo($end));

        return [
            'count' => $orders->count(),
            'cards' => $orders->take(self::COLUMN_LIMIT)
                ->map(fn (OnlineOrder $o) => OrderRows::card($o, $now) + ($start !== null ? ['otherDay' => $otherDay($o)] : []))->values()->all(),
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
            ->get(['items_total', 'grand_total'])->map(fn (OnlineOrder $o) => $o->grandTotal());
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
     * Open orders for the coming days (shop time), for the "Coming up" chips under today's board (new ones are also
     * in the New column).
     *
     * @return list<array{day: string, count: int}>
     */
    private function later(OrderBoardFilters $filters, CarbonImmutable $end): array
    {
        $zone = OnlineStoreSwitch::timezone();

        return $this->orders->query()->when($filters->shop, fn (Builder $q, string $shop) => $q->where('branch_id', $shop))
            ->whereIn('status', [OnlineOrderStatus::New, OnlineOrderStatus::Picking, OnlineOrderStatus::Ready, OnlineOrderStatus::OutForDelivery])
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
