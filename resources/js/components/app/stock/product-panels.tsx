import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type ReactNode } from 'react';
import { BasisPill, formatDateTime, formatDay, money, Qty, StockStatusPill } from './format';
import { type ProductStockProps } from './types';

const dash = <span className="text-muted-foreground">—</span>;
const right = 'text-right tabular-nums';

function Empty({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground px-6 py-8 text-center text-sm">{children}</p>;
}

/** Each shop's stock line of the product, with its low-stock point and FIFO value. */
export function ShopLines({ lines }: { lines: ProductStockProps['lines'] }) {
    return (
        <SectionCard title="By shop" description="The tills’ own figures. A shop’s reorder point, min and max are set on its till." flush>
            {lines.length === 0 ? (
                <Empty>No shop has sent a stock line for this product yet.</Empty>
            ) : (
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Shop</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">On hand</TableHead>
                                <TableHead className="text-right">Reserved</TableHead>
                                <TableHead className="text-right">Low at</TableHead>
                                <TableHead className="text-right">Min / max</TableHead>
                                <TableHead className="text-right">FIFO value</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((l) => (
                                <TableRow key={l.id}>
                                    <TableCell className="font-medium">{l.shop}</TableCell>
                                    <TableCell>
                                        <StockStatusPill status={l.status} />
                                    </TableCell>
                                    <TableCell className={right}>
                                        <Qty value={l.onHand} className="font-medium" />
                                    </TableCell>
                                    <TableCell className={right}>
                                        <Qty value={l.reserved} className="text-muted-foreground" />
                                    </TableCell>
                                    <TableCell className={right}>
                                        <Qty value={l.lowAt} />
                                        {l.reorderPoint !== null && <span className="text-muted-foreground block text-xs">shop reorder point</span>}
                                    </TableCell>
                                    <TableCell className={right}>
                                        {l.min === null && l.max === null ? (
                                            dash
                                        ) : (
                                            <span>
                                                {l.min !== null ? <Qty value={l.min} /> : '—'} / {l.max !== null ? <Qty value={l.max} /> : '—'}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className={right}>
                                        <span className="block">{money(l.fifoValue)}</span>
                                        {l.basis !== 'fifo' && l.basis !== 'none' && <BasisPill basis={l.basis} />}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </SectionCard>
    );
}

/** What is left of each delivery and what it cost (the till's FIFO cost layers), newest first. */
export function CostLayers({ layers }: { layers: ProductStockProps['layers'] }) {
    return (
        <SectionCard title="Cost layers" description="What is left of each delivery at its own cost. Sales use the oldest first." flush>
            {layers.length === 0 ? (
                <Empty>No cost layers: the stock is valued at the product cost price.</Empty>
            ) : (
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Received</TableHead>
                                <TableHead>Shop</TableHead>
                                <TableHead className="text-right">Left</TableHead>
                                <TableHead className="text-right">Unit cost</TableHead>
                                <TableHead className="text-right">Value</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {layers.map((l) => (
                                <TableRow key={l.id}>
                                    <TableCell className="whitespace-nowrap">{formatDateTime(l.receivedAt)}</TableCell>
                                    <TableCell>{l.shop}</TableCell>
                                    <TableCell className={right}>
                                        <Qty value={l.qty} />
                                    </TableCell>
                                    <TableCell className={right}>{money(l.unitCost)}</TableCell>
                                    <TableCell className={right}>{money(l.value)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </SectionCard>
    );
}

/** The product's batches with a best-before date, soonest first. */
export function Batches({ batches, today }: { batches: ProductStockProps['batches']; today: string }) {
    return (
        <SectionCard title="Batches and dates" description="Deliveries with a batch number or best-before date." flush>
            {batches.length === 0 ? (
                <Empty>No dated batches for this product.</Empty>
            ) : (
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Best before</TableHead>
                                <TableHead>Batch</TableHead>
                                <TableHead>Shop</TableHead>
                                <TableHead className="text-right">Left</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {batches.map((b) => (
                                <TableRow key={b.id}>
                                    <TableCell className="whitespace-nowrap">
                                        {b.expiry ? formatDay(b.expiry) : dash}
                                        {b.expiry && b.expiry < today && (
                                            <StatusPill tone="danger" className="ml-2">
                                                Out of date
                                            </StatusPill>
                                        )}
                                    </TableCell>
                                    <TableCell>{b.batch ?? dash}</TableCell>
                                    <TableCell>{b.shop}</TableCell>
                                    <TableCell className={right}>
                                        <Qty value={b.qty} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </SectionCard>
    );
}
