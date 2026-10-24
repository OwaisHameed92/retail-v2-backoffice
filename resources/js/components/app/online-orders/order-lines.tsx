import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatMoney } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { type OrderLine } from './types';

/** "2", or "0.75 kg" for a weighed line. */
export function lineQuantity(line: OrderLine): string {
    return line.isWeighed ? `${line.quantity} kg` : line.quantity;
}

/**
 * The order's lines with quantities, shelf prices and the total; weighed lines are marked as estimates, lines the shop
 * changed or marked not available say so (OSA-18). `action` adds a last column (the line change menu), `extra` rows
 * go above the total (the delivery fee, OS-9).
 */
export function OrderLines({
    lines,
    total,
    pickList = false,
    action,
    extra,
}: {
    lines: OrderLine[];
    total: string;
    pickList?: boolean;
    action?: (line: OrderLine) => ReactNode;
    extra?: { label: string; value: string }[];
}) {
    const weighed = lines.some((line) => line.isWeighed && line.outcome !== 'unavailable');
    const span = pickList ? 4 : 3;

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    {pickList && <TableHead className="w-10">Picked</TableHead>}
                    <TableHead>Item</TableHead>
                    <TableHead className="text-right">Qty</TableHead>
                    <TableHead className="text-right">Price</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    {action && (
                        <TableHead className="w-10">
                            <span className="sr-only">Change</span>
                        </TableHead>
                    )}
                </TableRow>
            </TableHeader>
            <TableBody>
                {lines.map((line) => (
                    <TableRow key={line.id} className={cn('break-inside-avoid', line.outcome === 'unavailable' && 'text-muted-foreground')}>
                        {pickList && (
                            <TableCell>
                                <span className="inline-block size-4 rounded-sm border-2 border-current" aria-hidden />
                            </TableCell>
                        )}
                        <TableCell className="font-medium whitespace-normal">
                            {line.name}
                            {line.outcome === 'unavailable' && (
                                <span className="text-danger-foreground block text-xs font-semibold">Not available: not supplied</span>
                            )}
                            {line.outcome === 'changed' && line.orderedQuantity && (
                                <span className="text-warning-foreground block text-xs font-semibold">
                                    Changed: {line.isWeighed ? `${line.orderedQuantity} kg` : line.orderedQuantity} ordered
                                </span>
                            )}
                            {line.isWeighed && line.outcome !== 'unavailable' && (
                                <span className="text-muted-foreground block text-xs font-normal">Weighed at the till: price is an estimate</span>
                            )}
                        </TableCell>
                        <TableCell className={cn('text-right tabular-nums', pickList && 'text-base font-semibold')}>{lineQuantity(line)}</TableCell>
                        <TableCell className="text-right tabular-nums">
                            {formatMoney(line.unitPrice)}
                            {line.isWeighed && '/kg'}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {line.isWeighed && '≈ '}
                            {formatMoney(line.lineTotal)}
                        </TableCell>
                        {action && <TableCell className="text-right print:hidden">{action(line)}</TableCell>}
                    </TableRow>
                ))}
            </TableBody>
            <TableFooter>
                {extra?.map((row) => (
                    <TableRow key={row.label}>
                        <TableCell colSpan={span}>{row.label}</TableCell>
                        <TableCell className="text-right tabular-nums">{row.value}</TableCell>
                        {action && <TableCell />}
                    </TableRow>
                ))}
                <TableRow>
                    <TableCell colSpan={span} className="font-semibold">
                        {weighed ? 'Estimated total' : 'Total'}
                    </TableCell>
                    <TableCell className="text-right font-semibold tabular-nums">{formatMoney(total)}</TableCell>
                    {action && <TableCell />}
                </TableRow>
            </TableFooter>
        </Table>
    );
}
