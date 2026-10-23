import { BoardFiltersBar, TakingOrdersToggle } from '@/components/app/online-orders/board-toolbar';
import { dayLabel } from '@/components/app/online-orders/format';
import { OrderBoard } from '@/components/app/online-orders/order-board';
import { type Board, type OnlineOrdersIndexProps } from '@/components/app/online-orders/types';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney, formatNumber, formatTime } from '@/lib/country';
import { Head, Link, router } from '@inertiajs/react';
import { Banknote, CalendarClock, Hourglass, LoaderCircle, Receipt, RefreshCw, ShoppingBag } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const REFRESH_MS = 30_000;
const HIGHLIGHT_MS = 120_000;

const idsOf = (board: Board) => Object.values(board.columns).flatMap((column) => column.cards.map((card) => card.id));

/**
 * Reloads the board every 30 seconds while the tab is visible (and as soon as it becomes visible again), and
 * returns the ids of orders that arrived since the page opened, for a while, so they stand out.
 */
function useLiveBoard(board: Board, view: string) {
    const [refreshing, setRefreshing] = useState(false);
    const [highlighted, setHighlighted] = useState<Set<string>>(new Set());
    const known = useRef<Set<string>>(new Set(idsOf(board)));
    const shown = useRef(view);

    const refresh = useCallback(() => {
        router.reload({ only: ['board', 'store', 'onlineOrdersNew'], onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) });
    }, []);

    useEffect(() => {
        const tick = () => document.visibilityState === 'visible' && refresh();
        const timer = window.setInterval(tick, REFRESH_MS);
        document.addEventListener('visibilitychange', tick);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', tick);
        };
    }, [refresh]);

    useEffect(() => {
        if (shown.current !== view) {
            // Another day or shop: its orders are not "new arrivals".
            shown.current = view;
            known.current = new Set(idsOf(board));

            return;
        }
        const arrived = board.columns.new.cards.filter((card) => !known.current.has(card.id));
        idsOf(board).forEach((id) => known.current.add(id));
        if (arrived.length === 0) {
            return;
        }
        showToast(arrived.length === 1 ? `New online order ${arrived[0].reference}` : `${arrived.length} new online orders`);
        setHighlighted((current) => new Set([...current, ...arrived.map((card) => card.id)]));
        const timer = window.setTimeout(
            () => setHighlighted((current) => new Set([...current].filter((id) => !arrived.some((card) => card.id === id)))),
            HIGHLIGHT_MS,
        );

        return () => window.clearTimeout(timer);
    }, [board, view]);

    return { refresh, refreshing, highlighted };
}

/** Portal → Online orders (OS-4): today's click & collect orders as a board, worked from New to Done. */
export default function OnlineOrdersIndex({ store, setupUrl, filters, options, board }: OnlineOrdersIndexProps) {
    const { refresh, refreshing, highlighted } = useLiveBoard(board, `${filters.day}|${filters.shop ?? 'all'}`);
    const { stats } = board;
    const when = filters.isToday ? 'today' : `on ${dayLabel(filters.day)}`;
    const showShop = !filters.shopLocked && filters.shop === null && options.shops.length > 1;

    return (
        <AppLayout>
            <Head title="Online orders" />
            <PageHeader
                title="Online orders"
                description="Click & collect orders from your online store. Customers pay at the shop when they collect."
                actions={
                    store &&
                    (store.isLive ? (
                        !filters.shopLocked && <TakingOrdersToggle taking={store.takingOrders} />
                    ) : (
                        <StatusPill tone="warning">Store not live yet</StatusPill>
                    ))
                }
            />

            {store === null ? (
                <EmptyState
                    icon={ShoppingBag}
                    title="Your online store is not set up yet"
                    body="Set up your online store first: choose a look, the shops customers collect from and the products to show. Orders appear here as soon as customers start ordering."
                    action={
                        setupUrl && (
                            <Button asChild>
                                <Link href={setupUrl}>Set up the online store</Link>
                            </Button>
                        )
                    }
                    bordered
                />
            ) : (
                <div className="grid gap-6">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <BoardFiltersBar filters={filters} shops={options.shops} />
                        <Button variant="outline" className="h-9 shrink-0" onClick={refresh} disabled={refreshing}>
                            {refreshing ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <RefreshCw className="size-4" aria-hidden />}
                            Refresh
                        </Button>
                    </div>

                    <StatGrid>
                        <StatCard
                            label={`Orders ${when}`}
                            value={formatNumber(stats.orders)}
                            hint="To collect, not counting cancelled"
                            icon={Receipt}
                        />
                        <StatCard
                            label={`Value ${when}`}
                            value={formatMoney(stats.value)}
                            hint="Shelf prices; paid at the shop"
                            icon={Banknote}
                            tone="success"
                        />
                        <StatCard
                            label="Average order"
                            value={stats.average === null ? '—' : formatMoney(stats.average)}
                            hint={`Orders ${when}`}
                            icon={ShoppingBag}
                            tone="neutral"
                        />
                        <StatCard
                            label="Waiting to start"
                            value={formatNumber(stats.waiting)}
                            hint={stats.waiting > 0 ? 'New orders not picked yet' : 'All started'}
                            icon={Hourglass}
                            tone={stats.waiting > 0 ? 'warning' : 'neutral'}
                        />
                    </StatGrid>

                    <OrderBoard board={board} isToday={filters.isToday} showShop={showShop} highlighted={highlighted} />

                    <div className="text-muted-foreground flex flex-col gap-3 text-sm sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                        {board.later.length > 0 ? (
                            <div className="flex flex-wrap items-center gap-2">
                                <CalendarClock className="size-4" aria-hidden />
                                <span>Coming up:</span>
                                {board.later.map((day) => (
                                    <Link
                                        key={day.day}
                                        href={route('app.online-orders.index', { day: day.day, shop: filters.shop ?? 'all' })}
                                        className="bg-card hover:border-primary hover:text-primary rounded-full border px-2.5 py-0.5 text-xs font-medium"
                                    >
                                        {dayLabel(day.day)} · {formatNumber(day.count)}
                                    </Link>
                                ))}
                            </div>
                        ) : (
                            <span>
                                {filters.isToday ? 'No orders for the coming days yet.' : `Showing orders to collect on ${formatDate(filters.day)}.`}
                            </span>
                        )}
                        <span className="tabular-nums">
                            Updated {formatTime(board.refreshedAt, { second: '2-digit' })} · refreshes every 30 seconds
                        </span>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
