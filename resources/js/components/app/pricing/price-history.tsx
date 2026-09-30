import { OptionSelect } from '@/components/app/products/fields';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { History } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/shared/empty-state';
import { changedAtLabel, formatDateTime, PRICE_TONES, pounds } from './format';
import { type PriceRow, type Shop } from './types';

/** Every shop price row of a product, newest first, filterable by shop (module 4.3). */
export function PriceHistory({ rows, shops, limited }: { rows: PriceRow[]; shops: Shop[]; limited: boolean }) {
    const [shop, setShop] = useState('');
    const shown = shop === '' ? rows : rows.filter((r) => r.branchId === shop);

    return (
        <SectionCard
            title="Price history"
            description={limited ? 'The latest 200 shop prices.' : 'Every shop price, newest first. Nothing is ever overwritten: a new price is a new row.'}
            actions={
                shops.length > 1 ? (
                    <div className="w-48">
                        <OptionSelect id="history-shop" value={shop} none="All shops" options={shops.map((s) => ({ value: s.id, label: s.name }))} onChange={setShop} />
                    </div>
                ) : undefined
            }
            flush
        >
            {shown.length === 0 ? (
                <EmptyState icon={History} title="No shop prices yet" body="Every shop sells at the business price." />
            ) : (
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Shop</TableHead>
                                <TableHead className="text-right">Price</TableHead>
                                <TableHead>From</TableHead>
                                <TableHead>Until</TableHead>
                                <TableHead>Last changed by</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shown.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <div className="grid leading-5">
                                            <span>{row.shop ?? 'Another shop'}</span>
                                            {row.unit && <span className="text-muted-foreground text-xs">{row.unit}</span>}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">{pounds(row.price)}</TableCell>
                                    <TableCell className="whitespace-nowrap">{formatDateTime(row.validFrom)}</TableCell>
                                    <TableCell className="whitespace-nowrap">{row.validTo ? formatDateTime(row.validTo) : 'Until changed'}</TableCell>
                                    <TableCell>{changedAtLabel(row.changedAt)}</TableCell>
                                    <TableCell>
                                        <StatusBadge status={row.status} tones={PRICE_TONES} />
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
