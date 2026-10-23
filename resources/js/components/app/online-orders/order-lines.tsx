import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatMoney } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type OrderLine } from './types';

/** "2", or "0.75 kg" for a weighed line. */
export function lineQuantity(line: OrderLine): string {
    return line.isWeighed ? `${line.quantity} kg` : line.quantity;
}

/** The order's lines with quantities, shelf prices and the total; weighed lines are marked as estimates. */
export function OrderLines({ lines, total, pickList = false }: { lines: OrderLine[]; total: string; pickList?: boolean }) {
    const weighed = lines.some((line) => line.isWeighed);

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    {pickList && <TableHead className="w-10">Picked</TableHead>}
                    <TableHead>Item</TableHead>
                    <TableHead className="text-right">Qty</TableHead>
                    <TableHead className="text-right">Price</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {lines.map((line) => (
                    <TableRow key={line.id} className="break-inside-avoid">
                        {pickList && (
                            <TableCell>
                                <span className="inline-block size-4 rounded-sm border-2 border-current" aria-hidden />
                            </TableCell>
                        )}
                        <TableCell className="font-medium whitespace-normal">
                            {line.name}
                            {line.isWeighed && (
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
                    </TableRow>
                ))}
            </TableBody>
            <TableFooter>
                <TableRow>
                    <TableCell colSpan={pickList ? 4 : 3} className="font-semibold">
                        {weighed ? 'Estimated total' : 'Total'}
                    </TableCell>
                    <TableCell className="text-right font-semibold tabular-nums">{formatMoney(total)}</TableCell>
                </TableRow>
            </TableFooter>
        </Table>
    );
}
