import { copies, formatDay, money, NewsTabs, percent } from '@/components/app/news/format';
import { type NewsFigures, type SummaryProps } from '@/components/app/news/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { ChartCard } from '@/components/shared/chart-card';
import { EmptyState } from '@/components/shared/empty-state';
import { MoneyIcon } from '@/components/shared/money-icon';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { TrendChart } from '@/components/shared/trend-chart';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Info, Newspaper, Ticket, TrendingUp, Undo2 } from 'lucide-react';

const HEADS = ['In', 'Sold', 'Returned', 'Sell-through', 'Net cost', 'Sales', 'Margin'];

function FigureCells({ f }: { f: NewsFigures }) {
    return (
        <>
            <TableCell className="text-right tabular-nums">{copies(f.qtyIn)}</TableCell>
            <TableCell className="text-right tabular-nums">{copies(f.qtySold)}</TableCell>
            <TableCell className="text-right tabular-nums">{copies(f.qtyReturned)}</TableCell>
            <TableCell className="text-right tabular-nums">{percent(f.sellThrough)}</TableCell>
            <TableCell className="text-right tabular-nums">{money(f.netCost)}</TableCell>
            <TableCell className="text-right tabular-nums">{money(f.sales)}</TableCell>
            <TableCell className={cn('text-right font-medium tabular-nums', Number(f.margin) < 0 && 'text-danger-foreground')}>
                {money(f.margin)}
                {f.marginPercent !== null && <span className="text-muted-foreground ml-1 text-xs font-normal">{percent(f.marginPercent)}</span>}
            </TableCell>
        </>
    );
}

/** The weekly news summary per shop and per title (module 5.8): sold vs returned, cost, credit and margin. */
export default function NewsSummary({ week, shop, byShop, total, titles, trend, shops, oneShop }: SummaryProps) {
    const go = (params: Record<string, string | undefined>) =>
        router.get(
            route('app.news.summary'),
            { week: week.start, shop: shop ?? undefined, ...params },
            { preserveScroll: true, preserveState: true },
        );
    const label = `${formatDay(week.start)} – ${formatDay(week.end)}`;
    const empty = total.qtyIn === 0 && (total.vouchers ?? 0) === 0;

    return (
        <AppLayout>
            <Head title="Weekly summary · Newspapers" />

            <PageHeader
                title="Newspapers"
                description="What each shop sold and sent back this week, the credit due and the margin on the papers and magazines."
                tabs={<NewsTabs current="summary" />}
            />

            {oneShop && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>You are seeing {shops[0]?.name ?? 'your shop'} only.</AlertDescription>
                </Alert>
            )}

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="icon" onClick={() => go({ week: week.previous })} aria-label="Previous week">
                        <ChevronLeft />
                    </Button>
                    <p className="min-w-52 text-center text-sm font-medium">
                        {label}
                        {week.current && <span className="text-muted-foreground font-normal"> · this week</span>}
                    </p>
                    <Button
                        variant="outline"
                        size="icon"
                        disabled={!week.next}
                        onClick={() => week.next && go({ week: week.next })}
                        aria-label="Next week"
                    >
                        <ChevronRight />
                    </Button>
                </div>
                {!oneShop && shops.length > 1 && (
                    <FilterSelect
                        value={shop}
                        onChange={(next) => go({ shop: next })}
                        all="Every shop"
                        options={shops.map((s) => ({ value: s.id, label: s.name }))}
                        label="Filter by shop"
                    />
                )}
            </div>

            <StatGrid columns={4}>
                <StatCard
                    label="Sales"
                    value={money(total.sales)}
                    hint={`${copies(total.qtySold)} copies at cover price`}
                    icon={TrendingUp}
                    tone="primary"
                />
                <StatCard
                    label="Margin"
                    value={money(total.margin)}
                    hint={total.marginPercent === null ? 'After returns credit' : `${percent(total.marginPercent)} of sales, after returns`}
                    icon={MoneyIcon}
                    tone={Number(total.margin) < 0 ? 'danger' : 'success'}
                />
                <StatCard
                    label="Returned"
                    value={copies(total.qtyReturned)}
                    hint={`${money(total.credit)} credit · ${percent(total.qtyIn > 0 ? (100 * total.qtyReturned) / total.qtyIn : null)} of copies in`}
                    icon={Undo2}
                    tone="warning"
                />
                <StatCard
                    label="Vouchers taken"
                    value={copies(total.vouchers ?? 0)}
                    hint={`${money(total.voucherValue ?? '0')} to claim back`}
                    icon={Ticket}
                    tone="neutral"
                />
            </StatGrid>

            <ChartCard title="Last 8 weeks" subtitle="Sales at cover price, week by week" icon={TrendingUp}>
                <TrendChart
                    data={trend.map((t) => ({ label: formatDay(t.week).replace(/ \d{4}$/, ''), value: Number(t.sales) }))}
                    variant="bar"
                    seriesName="Sales"
                    format={(v) => money(String(v))}
                    className="h-56"
                />
            </ChartCard>

            {empty ? (
                <EmptyState
                    icon={Newspaper}
                    title="Nothing for this week yet"
                    body="News deliveries and vouchers recorded on the tills appear here after they sync."
                />
            ) : (
                <>
                    <SectionCard title="By shop" description="Deliveries dated this week; vouchers taken this week." flush>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Shop</TableHead>
                                    {HEADS.map((h) => (
                                        <TableHead key={h} className="text-right">
                                            {h}
                                        </TableHead>
                                    ))}
                                    <TableHead className="text-right">Vouchers</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {byShop.map((row) => (
                                    <TableRow key={row.shopId}>
                                        <TableCell className="font-medium">{row.shop}</TableCell>
                                        <FigureCells f={row} />
                                        <TableCell className="text-right tabular-nums">{money(row.voucherValue ?? '0')}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            {byShop.length > 1 && (
                                <TableFooter>
                                    <TableRow>
                                        <TableCell className="font-medium">Total</TableCell>
                                        <FigureCells f={total} />
                                        <TableCell className="text-right tabular-nums">{money(total.voucherValue ?? '0')}</TableCell>
                                    </TableRow>
                                </TableFooter>
                            )}
                        </Table>
                    </SectionCard>

                    <SectionCard title="By title" description="Best sellers first." flush>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Title</TableHead>
                                    {HEADS.map((h) => (
                                        <TableHead key={h} className="text-right">
                                            {h}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {titles.map((row, i) => (
                                    <TableRow key={`${row.title}-${i}`}>
                                        <TableCell>
                                            <div className="grid leading-5">
                                                <span className="font-medium">{row.title}</span>
                                                {row.publisher && <span className="text-muted-foreground text-xs">{row.publisher}</span>}
                                            </div>
                                        </TableCell>
                                        <FigureCells f={row} />
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </SectionCard>
                </>
            )}
        </AppLayout>
    );
}
