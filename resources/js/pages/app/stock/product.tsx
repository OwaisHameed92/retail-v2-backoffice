import { money, qty } from '@/components/app/stock/format';
import { LevelsDialog } from '@/components/app/stock/levels-dialog';
import { movementColumns } from '@/components/app/stock/movement-columns';
import { Batches, CostLayers, ShopLines } from '@/components/app/stock/product-panels';
import { type ProductStockProps } from '@/components/app/stock/types';
import { DataTable } from '@/components/shared/data-table';
import { DescriptionList } from '@/components/shared/description-list';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeftRight, Boxes, Layers, PoundSterling, SlidersHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';

const NEGATIVE: Record<string, string> = { allow: 'Allowed', warn: 'Allowed with a warning', block: 'Blocked' };

/** One product's stock (module 5.1): each shop, its own stock levels (editable with stock.manage), cost layers, batches, latest movements. */
export default function StockProduct({ product, lines, totals, layers, batches, recent, filters, canManage }: ProductStockProps) {
    const [editing, setEditing] = useState(false);
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/London' }).format(new Date());
    const columns = useMemo(() => movementColumns({ product: false }), []);
    const shopParam = filters.shopLocked ? {} : filters.shop ? { shop: filters.shop } : {};
    const levels = (value: string | null) => (value === null ? <span className="text-muted-foreground">Not set</span> : qty(value));

    return (
        <AppLayout>
            <Head title={`Stock · ${product.name}`} />

            <PageHeader
                title={product.name}
                back={{ href: route('app.stock.index', shopParam), label: 'Stock' }}
                status={
                    !product.isActive ? (
                        <StatusPill tone="neutral">Archived</StatusPill>
                    ) : !product.trackStock ? (
                        <StatusPill tone="warning">Stock not tracked</StatusPill>
                    ) : undefined
                }
                description={[product.sku, `Cost price ${money(product.costPrice)}`].filter(Boolean).join(' · ')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={route('app.stock.movements', { product: product.id, ...shopParam })}>
                                <ArrowLeftRight />
                                All movements
                            </Link>
                        </Button>
                        {canManage && (
                            <Button onClick={() => setEditing(true)}>
                                <SlidersHorizontal />
                                Stock levels
                            </Button>
                        )}
                    </div>
                }
            />

            <StatGrid columns={3}>
                <StatCard label="On hand" value={qty(totals.onHand)} hint={`${lines.length} ${lines.length === 1 ? 'shop' : 'shops'}`} icon={Boxes} />
                <StatCard label="FIFO value" value={money(totals.fifoValue)} hint="From the tills’ cost layers" icon={Layers} tone="success" />
                <StatCard
                    label="At today’s cost price"
                    value={money(totals.costValue)}
                    hint="On hand above zero × cost price"
                    icon={PoundSterling}
                    tone="neutral"
                />
            </StatGrid>

            <div className="grid gap-4 lg:grid-cols-3 lg:items-start">
                <div className="grid gap-4 lg:col-span-2">
                    <ShopLines lines={lines} />
                    <SectionCard
                        title="Latest movements"
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={route('app.stock.movements', { product: product.id, ...shopParam })}>View all</Link>
                            </Button>
                        }
                        flush
                    >
                        <DataTable
                            columns={columns}
                            data={recent}
                            meta={{ page: 1, perPage: recent.length || 10, total: recent.length }}
                            searchable={false}
                            getRowId={(row) => row.id}
                            footer={
                                recent.length > 0 ? (
                                    <p className="text-muted-foreground text-xs">The latest {recent.length} of this product’s movements.</p>
                                ) : undefined
                            }
                            empty={
                                <EmptyState
                                    icon={ArrowLeftRight}
                                    title="No movements yet"
                                    body="Sales, deliveries and adjustments of this product appear here."
                                />
                            }
                        />
                    </SectionCard>
                    <CostLayers layers={layers} />
                </div>
                <div className="grid gap-4">
                    <SectionCard
                        title="Stock levels"
                        description="For every shop, sent to the tills. A shop’s own reorder point, min and max come first."
                        actions={
                            canManage && (
                                <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
                                    Edit
                                </Button>
                            )
                        }
                    >
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Minimum (low at)', value: levels(product.minStockQty) },
                                { label: 'Most to hold', value: levels(product.maxStockQty) },
                                { label: 'Reorder quantity', value: levels(product.reorderQty) },
                                {
                                    label: 'Selling below zero',
                                    value: product.negativeStockMode
                                        ? (NEGATIVE[product.negativeStockMode] ?? product.negativeStockMode)
                                        : 'Till setting',
                                },
                                { label: 'Tracks best-before dates', value: product.tracksExpiryDates ? 'Yes' : 'No' },
                            ]}
                        />
                        {product.minStockQty === null && (
                            <p className="text-muted-foreground mt-3 text-xs">
                                Without a minimum, each shop’s low-stock setting applies (5 unless changed in Till settings).
                            </p>
                        )}
                    </SectionCard>
                    <Batches batches={batches} today={today} />
                </div>
            </div>

            {canManage && (
                <LevelsDialog
                    key={`${product.minStockQty}-${product.maxStockQty}-${product.reorderQty}`}
                    product={product}
                    open={editing}
                    onOpenChange={setEditing}
                />
            )}
        </AppLayout>
    );
}
