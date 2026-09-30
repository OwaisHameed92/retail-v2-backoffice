import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Amount, deduction, DISCOUNT_SOURCE, LINE_FLAGS, money, qty, trimRate } from './format';
import { type ReceiptLine, type SaleShowProps } from './types';

const isZero = (value: string) => Number(value) === 0;

/** Discounts on one line: the manual / staff / group amount, the promotion and any coupon, each with its source. */
function LineDeductions({ line }: { line: ReceiptLine }) {
    const rows: { label: string; amount: string }[] = [];

    if (!isZero(line.ownDiscount)) {
        const known = line.discountSource && line.discountSource !== 'promotion' && line.discountSource !== 'coupon';
        const source = known ? (DISCOUNT_SOURCE[line.discountSource as string] ?? 'Discount') : 'Discount';
        rows.push({ label: line.discountReason ? `${source}: ${line.discountReason}` : source, amount: line.ownDiscount });
    }
    if (!isZero(line.promotionDiscount)) {
        rows.push({ label: line.promotionName ? `Promotion: ${line.promotionName}` : 'Promotion', amount: line.promotionDiscount });
    }
    if (!isZero(line.couponDiscount)) {
        rows.push({ label: 'Coupon', amount: line.couponDiscount });
    }
    if (!isZero(line.deposit)) {
        rows.push({ label: 'Container deposit (DRS)', amount: `-${line.deposit}` });
    }

    return rows.length === 0 ? null : (
        <ul className="mt-1 grid gap-0.5">
            {rows.map((row) => (
                <li key={row.label} className="text-muted-foreground flex justify-between gap-3 text-xs">
                    <span>{row.label}</span>
                    <span className="tabular-nums">{Number(row.amount) < 0 ? `+${money(Math.abs(Number(row.amount)))}` : deduction(row.amount)}</span>
                </li>
            ))}
        </ul>
    );
}

function TotalRow({
    label,
    value,
    strong = false,
    muted = false,
    hint,
}: {
    label: string;
    value: string;
    strong?: boolean;
    muted?: boolean;
    hint?: string;
}) {
    return (
        <div
            className={cn(
                'flex items-baseline justify-between gap-4 py-1 text-sm',
                strong && 'border-t pt-2 text-base font-semibold',
                muted && 'text-muted-foreground',
            )}
        >
            <span>
                {label}
                {hint && <span className="text-muted-foreground ml-1 text-xs font-normal">{hint}</span>}
            </span>
            <Amount value={value} />
        </div>
    );
}

/** The receipt body: every stored line with its discounts, then the stored totals and the VAT breakdown. */
export function ReceiptLines({
    lines,
    totals,
    vat,
    canViewProducts,
    voided,
}: Pick<SaleShowProps, 'lines' | 'totals' | 'vat' | 'canViewProducts'> & { voided: boolean }) {
    return (
        <SectionCard title="Items" description={`${lines.length} ${lines.length === 1 ? 'line' : 'lines'} as the till recorded them`} flush>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Item</TableHead>
                        <TableHead className="text-right">Qty</TableHead>
                        <TableHead className="text-right">Price</TableHead>
                        <TableHead className="hidden text-right sm:table-cell">VAT</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {lines.map((line) => (
                        <TableRow key={line.id} className="align-top hover:bg-transparent">
                            <TableCell className="max-w-80 whitespace-normal">
                                {line.productId && canViewProducts ? (
                                    <Link href={route('app.products.show', line.productId)} className="font-medium hover:underline">
                                        {line.name}
                                    </Link>
                                ) : (
                                    <span className="font-medium">{line.name}</span>
                                )}
                                {line.barcode && <span className="text-muted-foreground block font-mono text-xs">{line.barcode}</span>}
                                {(line.flags.length > 0 || line.reason || line.isRefundLine) && (
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {line.isRefundLine && <StatusPill tone="warning">Returned</StatusPill>}
                                        {line.flags.map((flag) => (
                                            <StatusPill key={flag} tone={flag === 'orderDeposit' || flag === 'charity' ? 'violet' : 'neutral'}>
                                                {LINE_FLAGS[flag] ?? flag}
                                            </StatusPill>
                                        ))}
                                        {line.reason && <StatusPill tone="neutral">Reason: {line.reason}</StatusPill>}
                                    </div>
                                )}
                                <LineDeductions line={line} />
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{qty(line.qty)}</TableCell>
                            <TableCell className="text-right tabular-nums">{money(line.unitPrice)}</TableCell>
                            <TableCell className="text-muted-foreground hidden text-right text-sm tabular-nums sm:table-cell">
                                {trimRate(line.vatRate)}% · {money(line.vatAmount)}
                            </TableCell>
                            <TableCell className="text-right font-medium">
                                <Amount value={line.lineTotal} voided={voided} />
                            </TableCell>
                        </TableRow>
                    ))}
                    {lines.length === 0 && (
                        <TableRow>
                            <TableCell colSpan={5} className="text-muted-foreground py-8 text-center">
                                No lines on this receipt yet. Lines arrive with the till's next sync.
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
                {vat.length > 0 && (
                    <TableFooter className="bg-subtle">
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={5} className="py-3">
                                <p className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">VAT breakdown</p>
                                <div className="grid gap-1">
                                    {vat.map((row, i) => (
                                        <div key={`${row.code}-${i}`} className="grid grid-cols-4 gap-2 text-sm tabular-nums">
                                            <span>
                                                {row.code ? `${row.code} · ` : ''}
                                                {trimRate(row.rate)}%
                                            </span>
                                            <span className="text-right">Net {money(row.net)}</span>
                                            <span className="text-right">VAT {money(row.vat)}</span>
                                            <span className="text-right">Gross {money(row.gross)}</span>
                                        </div>
                                    ))}
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                )}
            </Table>
            <div className="border-t px-4 py-3 sm:px-6">
                <div className="ml-auto max-w-sm">
                    <TotalRow label="Subtotal" value={totals.subtotal} />
                    {!isZero(totals.discount) && (
                        <TotalRow
                            label="Discounts"
                            hint={isZero(totals.promo) ? undefined : `incl. ${money(totals.promo)} promotions`}
                            value={`-${Math.abs(Number(totals.discount))}`}
                            muted
                        />
                    )}
                    {!isZero(totals.deposit) && <TotalRow label="Container deposits (DRS)" value={totals.deposit} muted />}
                    <TotalRow label="Total" value={totals.total} strong />
                    <TotalRow label="of which VAT" value={totals.vat} muted />
                    <TotalRow label="Net of VAT" value={totals.net} muted />
                </div>
            </div>
        </SectionCard>
    );
}
