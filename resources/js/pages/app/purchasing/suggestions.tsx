import { planOrders } from '@/components/app/purchasing/suggestion-format';
import { SuggestionGroupCard } from '@/components/app/purchasing/suggestion-group';
import { SuggestionNotes } from '@/components/app/purchasing/suggestion-notes';
import { type Quantities, type SuggestionsProps } from '@/components/app/purchasing/suggestion-types';
import { FilterSelect } from '@/components/app/setup/fields';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { money } from '@/components/shared/trading/format';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatNumber, taxName, taxText } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ClipboardList, Info, PackageCheck, PackageSearch, RotateCcw, Search, ShoppingCart, Truck } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

// "all" is FilterSelect's own "clear" value, so the every-product view is "every" in the select.
const VIEWS: { value: string; label: string }[] = [
    { value: 'attention', label: 'Worth a look' },
    { value: 'every', label: 'Every product' },
];

/** Reorder suggestions (module 6.4): what each shop should order from each supplier, why, and draft orders from it. */
export default function ReorderSuggestions(props: SuggestionsProps) {
    const { filters, lines, groups, truncated, notable, stats, shops, suppliers, departments, settings, oneShop, can, ai } = props;
    const { errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const [quantities, setQuantities] = useState<Quantities>({});
    const [search, setSearch] = useState(filters.q ?? '');
    const [loading, setLoading] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const showShop = !oneShop && shops.length > 1;

    // New lines from the server (filters changed, orders drafted): start again from the suggestions.
    useEffect(() => setQuantities({}), [lines]);
    useEffect(() => () => clearTimeout(timer.current), []);

    const query = useMemo(() => {
        const q: Record<string, string> = {};
        for (const [key, value] of Object.entries(filters)) {
            if (value && !(key === 'view' && value === 'order')) {
                q[key] = value;
            }
        }
        return q;
    }, [filters]);

    const update = (changes: Record<string, string | undefined>) =>
        router.get(
            route('app.purchasing.suggestions.index'),
            { ...query, ...changes },
            { preserveState: true, preserveScroll: true, replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) },
        );

    const orders = useMemo(() => planOrders(lines, groups, quantities), [lines, groups, quantities]);
    const orderedLines = orders.reduce((n, o) => n + o.lines.length, 0);
    const total = orders.reduce((sum, o) => sum + o.cost, 0);
    const edited = Object.entries(quantities).some(([key, cases]) => lines.find((l) => l.key === key)?.suggestedCases !== cases);
    const byGroup = useMemo(() => groups.map((group) => ({ group, lines: lines.filter((l) => l.groupKey === group.key) })), [groups, lines]);
    const filtered = Boolean(filters.q || filters.supplier || filters.department || (!oneShop && filters.shop));

    const create = () =>
        new Promise<void>((resolve) =>
            router.post(
                route('app.purchasing.suggestions.store'),
                {
                    lines: orders.flatMap((o) =>
                        o.lines.map(({ line, cases }) => ({ shopId: line.shopId, supplierId: line.supplierId, productId: line.productId, cases })),
                    ),
                },
                { preserveScroll: true, onFinish: () => resolve() },
            ),
        );

    return (
        <AppLayout>
            <Head title="Reorder suggestions · Purchasing" />

            <PageHeader
                title="Reorder suggestions"
                back={{ href: route('app.purchasing.index', 'orders'), label: 'Orders' }}
                description={`What to order now, from each shop's sales over the last ${settings.historyWeeks} weeks (busy days, recent weeks and seasonal events), stock, orders already coming, supplier lead times, case sizes and shelf life.`}
                icon={ShoppingCart}
            />

            {oneShop && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        You are seeing {shops[0]?.name ?? 'your shop'} only. Someone who manages every shop places the orders.
                    </AlertDescription>
                </Alert>
            )}
            {errors.lines && (
                <Alert variant="destructive">
                    <AlertTriangle />
                    <AlertDescription>{errors.lines}</AlertDescription>
                </Alert>
            )}

            <StatGrid>
                <StatCard label="Lines to order" value={formatNumber(stats.lines)} icon={ClipboardList} hint="With at least one case suggested" />
                <StatCard label="Orders" value={formatNumber(stats.orders)} icon={Truck} hint="One per shop and supplier" tone="neutral" />
                <StatCard
                    label="Suggested cost"
                    value={money(stats.cost)}
                    icon={PackageCheck}
                    hint={taxText('Ex VAT, at supplier case cost')}
                    tone="success"
                />
                <StatCard
                    label="Worth a look"
                    value={formatNumber(stats.attention)}
                    icon={AlertTriangle}
                    hint="Running out, selling faster or slower, short life…"
                    tone={stats.attention > 0 ? 'warning' : 'neutral'}
                    href={filters.view === 'attention' ? undefined : route('app.purchasing.suggestions.index', { ...query, view: 'attention' })}
                />
            </StatGrid>

            <div className="flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center">
                <div className="relative w-full lg:w-64">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                    <Input
                        value={search}
                        placeholder="Search by product or SKU"
                        aria-label="Search products"
                        className="h-9 pl-8"
                        onChange={(e) => {
                            setSearch(e.target.value);
                            clearTimeout(timer.current);
                            timer.current = setTimeout(() => update({ q: e.target.value || undefined }), 350);
                        }}
                    />
                </div>
                {showShop && (
                    <FilterSelect
                        value={filters.shop}
                        onChange={(shop) => update({ shop })}
                        all="Every shop"
                        options={shops.map((s) => ({ value: s.id, label: s.name }))}
                        label="Filter by shop"
                    />
                )}
                <FilterSelect
                    value={filters.supplier}
                    onChange={(supplier) => update({ supplier })}
                    all="Every supplier"
                    options={suppliers.map((s) => ({ value: s.id, label: s.name }))}
                    label="Filter by supplier"
                />
                <FilterSelect
                    value={filters.department}
                    onChange={(department) => update({ department })}
                    all="Every department"
                    options={departments.map((d) => ({ value: d.id, label: d.name }))}
                    label="Filter by department"
                />
                <FilterSelect
                    value={filters.view === 'order' ? null : filters.view === 'all' ? 'every' : filters.view}
                    onChange={(view) => update({ view: view === 'every' ? 'all' : view })}
                    all="Needs ordering"
                    options={VIEWS}
                    label="Which lines"
                />
            </div>

            {truncated && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        Showing the first {formatNumber(lines.length)} lines. Choose a shop, supplier or department to see the rest.
                    </AlertDescription>
                </Alert>
            )}

            <div
                className={cn('grid grid-cols-[minmax(0,1fr)] gap-4 transition-opacity', loading && 'pointer-events-none opacity-60')}
                aria-busy={loading}
            >
                {filters.view !== 'attention' && (
                    <SuggestionNotes lines={notable} total={stats.attention} aiAvailable={ai.available} query={query} showShop={showShop} />
                )}

                {byGroup.length === 0 ? (
                    <EmptyState
                        bordered
                        icon={filtered || filters.view !== 'order' ? PackageSearch : PackageCheck}
                        tone={filtered || filters.view !== 'order' ? 'neutral' : 'success'}
                        title={
                            filtered ? 'No products match these filters' : filters.view === 'order' ? 'Nothing needs ordering' : 'No products to show'
                        }
                        body={
                            filtered
                                ? 'Try another product, supplier or department.'
                                : filters.view === 'order'
                                  ? "Every product linked to a supplier has enough stock to last until its next delivery. Products show here once they are linked to a supplier and the shop's till has sent its stock."
                                  : "Products show here once they are linked to a supplier and a shop's till has sent its stock."
                        }
                        action={
                            filters.view === 'order' && !filtered ? (
                                <Button variant="outline" onClick={() => update({ view: 'all' })}>
                                    See every product
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    byGroup.map(({ group, lines: groupLines }) => (
                        <SuggestionGroupCard
                            key={group.key}
                            group={group}
                            lines={groupLines}
                            quantities={quantities}
                            editable={can.manage}
                            showShop={showShop}
                            onChange={(key, cases) => setQuantities((q) => ({ ...q, [key]: cases }))}
                        />
                    ))
                )}
            </div>

            {byGroup.length > 0 && (
                <StickyFormBar
                    message={
                        orderedLines === 0
                            ? 'No cases chosen.'
                            : `${orderedLines} ${orderedLines === 1 ? 'line' : 'lines'} in ${orders.length} ${orders.length === 1 ? 'order' : 'orders'} · ${money(total)} ex ${taxName()}`
                    }
                >
                    {edited && (
                        <Button variant="outline" onClick={() => setQuantities({})}>
                            <RotateCcw />
                            Back to suggestions
                        </Button>
                    )}
                    {can.manage ? (
                        <Button disabled={orders.length === 0} onClick={() => setConfirming(true)}>
                            <ShoppingCart />
                            Create purchase {orders.length === 1 ? 'order' : 'orders'}
                        </Button>
                    ) : (
                        <Button variant="outline" asChild>
                            <Link href={route('app.purchasing.index', 'orders')}>See orders</Link>
                        </Button>
                    )}
                </StickyFormBar>
            )}

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Draft ${orders.length} ${orders.length === 1 ? 'order' : 'orders'}?`}
                description="Each is saved as a draft head-office order for its shop. Nothing goes to a supplier until you send it from the order."
                confirmLabel="Create drafts"
                onConfirm={create}
            >
                <ul className="max-h-72 divide-y overflow-y-auto rounded-md border text-sm">
                    {orders.map((order) => (
                        <li key={order.group.key} className="flex items-start justify-between gap-3 px-3 py-2">
                            <div className="min-w-0">
                                <div className="font-medium">{order.group.supplierName}</div>
                                <div className="text-muted-foreground text-xs">
                                    {order.group.shopName} · {order.lines.length} {order.lines.length === 1 ? 'line' : 'lines'}, {order.cases}{' '}
                                    {order.cases === 1 ? 'case' : 'cases'}
                                </div>
                            </div>
                            <span className="shrink-0 tabular-nums">{money(order.cost)}</span>
                        </li>
                    ))}
                </ul>
            </ConfirmDialog>
        </AppLayout>
    );
}
