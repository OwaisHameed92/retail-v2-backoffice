import { BasisPill, money, number, Qty, signedMoney, StockTabs } from '@/components/app/stock/format';
import { StockFilters } from '@/components/app/stock/stock-filters';
import { type ValuationGroup, type ValuationProps } from '@/components/app/stock/types';
import { useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { MoneyIcon } from '@/components/shared/money-icon';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Layers, Scale } from 'lucide-react';

const right = 'text-right tabular-nums';

function Groups({ title, label, rows, total }: { title: string; label: string; rows: ValuationGroup[]; total: string }) {
    return (
        <SectionCard title={title} flush>
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{label}</TableHead>
                            <TableHead className="text-right">Lines</TableHead>
                            <TableHead className="text-right">FIFO value</TableHead>
                            <TableHead className="text-right">At cost price</TableHead>
                            <TableHead className="text-right">Share</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((g) => (
                            <TableRow key={g.id || g.name}>
                                <TableCell className="font-medium">{g.name}</TableCell>
                                <TableCell className={right}>{number(g.lines)}</TableCell>
                                <TableCell className={right}>{money(g.fifo)}</TableCell>
                                <TableCell className={`${right} text-muted-foreground`}>{money(g.cost)}</TableCell>
                                <TableCell className={right}>
                                    {Number(total) > 0 ? `${((Number(g.fifo) / Number(total)) * 100).toFixed(1)}%` : '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </SectionCard>
    );
}

/**
 * Stock valuation (module 5.1): FIFO from the tills' cost layers (newest deliveries cover what is on hand; the rest at
 * the product cost price) next to the same stock at today's cost price.
 */
export default function StockValuation({ totals, byShop, byDepartment, top, filters, options }: ValuationProps) {
    const { update } = useTableQuery({ only: ['totals', 'byShop', 'byDepartment', 'top', 'filters'] });
    const diff = Number(totals.difference);

    return (
        <AppLayout>
            <Head title="Stock valuation" />

            <PageHeader
                title="Stock valuation"
                description="What the stock on hand cost you, first in first out: the newest deliveries still on the shelf at what you paid for them."
                tabs={<StockTabs active="valuation" shop={filters.shopLocked ? null : filters.shop} />}
            />

            <div className="flex flex-wrap items-center gap-2">
                <StockFilters filters={filters} options={options} update={update} show={{ department: true }} />
            </div>

            {totals.lines === 0 ? (
                <EmptyState
                    icon={Layers}
                    bordered
                    title="No stock to value"
                    body="Nothing is on hand for these filters. Values appear once a till has synced its stock."
                />
            ) : (
                <>
                    <StatGrid columns={3}>
                        <StatCard
                            label="FIFO value"
                            value={money(totals.fifoValue)}
                            hint={`${number(totals.lines)} lines with stock on hand`}
                            icon={Layers}
                            tone="success"
                        />
                        <StatCard
                            label="At today’s cost price"
                            value={money(totals.costValue)}
                            hint="On hand × product cost price"
                            icon={MoneyIcon}
                            tone="neutral"
                        />
                        <StatCard
                            label="Difference"
                            value={signedMoney(totals.difference)}
                            hint={
                                diff === 0
                                    ? 'Costs have not moved'
                                    : diff < 0
                                      ? 'Older, cheaper stock on the shelf'
                                      : 'Stock bought dearer than today’s cost price'
                            }
                            icon={Scale}
                            tone={diff === 0 ? 'neutral' : 'warning'}
                        />
                    </StatGrid>

                    <p className="text-muted-foreground text-sm">
                        {number(totals.basis.fifo)} lines valued fully from cost layers, {number(totals.basis.mixed)} partly (the rest at cost price),{' '}
                        {number(totals.basis.cost)} at cost price only (no layers)
                        {totals.basis.none > 0 ? `, ${number(totals.basis.none)} not valued (no layers and no cost price)` : ''}.
                    </p>

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:items-start">
                        <Groups title="By shop" label="Shop" rows={byShop} total={totals.fifoValue} />
                        <Groups title="By department" label="Department" rows={byDepartment} total={totals.fifoValue} />
                    </div>

                    <SectionCard title="Worth the most" description={`The ${top.length} stock lines with the highest FIFO value.`} flush>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Product</TableHead>
                                        <TableHead>Shop</TableHead>
                                        <TableHead className="text-right">On hand</TableHead>
                                        <TableHead>Basis</TableHead>
                                        <TableHead className="text-right">FIFO value</TableHead>
                                        <TableHead className="text-right">At cost price</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {top.map((r) => (
                                        <TableRow key={r.id}>
                                            <TableCell>
                                                <Link href={route('app.stock.products.show', r.productId)} className="font-medium hover:underline">
                                                    {r.name}
                                                </Link>
                                                {r.sku && <span className="text-muted-foreground block text-xs">{r.sku}</span>}
                                            </TableCell>
                                            <TableCell>{r.shop}</TableCell>
                                            <TableCell className={right}>
                                                <Qty value={r.onHand} />
                                            </TableCell>
                                            <TableCell>
                                                <BasisPill basis={r.basis} />
                                            </TableCell>
                                            <TableCell className={`${right} font-medium`}>{money(r.fifoValue)}</TableCell>
                                            <TableCell className={`${right} text-muted-foreground`}>{money(r.costValue)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </SectionCard>
                </>
            )}
        </AppLayout>
    );
}
