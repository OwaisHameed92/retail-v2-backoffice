import { AccountsFilters, AccountsPageLayout, Amount } from '@/components/app/accounts/accounts-page';
import { type VatProps } from '@/components/app/accounts/types';
import { VatBoxes } from '@/components/app/accounts/vat-boxes';
import { formatDateTime, formatDay } from '@/components/app/pricing/format';
import { useTableQuery } from '@/components/shared/data-table';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { money, number } from '@/components/shared/trading/format';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Download, Info, Printer } from 'lucide-react';

const STAGGERS = [
    { value: '1', label: 'Quarters ending Mar, Jun, Sep, Dec' },
    { value: '2', label: 'Quarters ending Apr, Jul, Oct, Jan' },
    { value: '3', label: 'Quarters ending May, Aug, Nov, Feb' },
];

export default function AccountsVat(props: VatProps) {
    const { quarter, quarters, boxes, position, sources, unreclaimedVat, rates, tillReturns, filters, options } = props;
    const { update } = useTableQuery();
    const query = { quarter: quarter.value, ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }) };

    return (
        <AccountsPageLayout
            tab="vat"
            filters={filters}
            title="VAT return · Accounts"
            description="Boxes 1 to 9 for a VAT quarter, worked out from your till sales, supplier invoices and expenses. Check them, then file."
            actions={
                <>
                    <Button variant="outline" asChild>
                        <a href={route('app.accounts.vat.csv', query)}>
                            <Download />
                            CSV
                        </a>
                    </Button>
                    <Button variant="outline" asChild>
                        <a href={route('app.accounts.vat.print', query)}>
                            <Printer />
                            Print
                        </a>
                    </Button>
                </>
            }
        >
            <AccountsFilters filters={filters} options={options} update={update} dates={false}>
                <Select value={String(quarter.stagger)} onValueChange={(stagger) => update({ stagger, quarter: undefined })}>
                    <SelectTrigger className="h-9 w-full sm:w-72" aria-label="VAT quarters">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {STAGGERS.map((s) => (
                            <SelectItem key={s.value} value={s.value}>
                                {s.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={quarter.value} onValueChange={(q) => update({ quarter: q, stagger: undefined })}>
                    <SelectTrigger className="h-9 w-full sm:w-56" aria-label="VAT quarter">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {quarters.map((q) => (
                            <SelectItem key={q.value} value={q.value}>
                                {q.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </AccountsFilters>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <SectionCard
                    title={`${formatDay(quarter.from)} to ${formatDay(quarter.to)}`}
                    description={position === 'reclaim' ? 'HMRC owes you the box 5 amount.' : 'You owe HMRC the box 5 amount.'}
                >
                    <VatBoxes boxes={boxes} position={position} />
                </SectionCard>
                <div className="grid content-start gap-6">
                    <Alert variant="info">
                        <Info />
                        <AlertTitle>Not filed from here</AlertTitle>
                        <AlertDescription>
                            This is a helper: the portal does not send returns to HMRC. File through Making Tax Digital software or your accountant.
                            Filing from the portal (MTD) is planned for later.
                        </AlertDescription>
                    </Alert>
                    <SectionCard title="Where the figures come from" flush>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Source</TableHead>
                                    <TableHead className="text-right">Net</TableHead>
                                    <TableHead className="text-right">VAT</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sources.map((s) => (
                                    <TableRow key={s.key}>
                                        <TableCell>
                                            <span className="font-medium">{s.label}</span>
                                            <p className="text-muted-foreground text-xs">
                                                Boxes {s.boxes}
                                                {s.count !== null && ` · ${number(s.count)} ${s.count === 1 ? 'item' : 'items'}`}
                                            </p>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount value={s.net} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount value={s.vat} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        {Number(unreclaimedVat) !== 0 && (
                            <p className="text-muted-foreground border-t px-5 py-3 text-sm sm:px-6">
                                {money(unreclaimedVat)} of VAT on expenses is left out: the shop holds no VAT receipt for it.
                            </p>
                        )}
                    </SectionCard>
                </div>
            </div>

            <SectionCard title="Sales by VAT rate" description="Till sales net of refunds, as in the VAT report. Order deposits and charity round-ups are not sales." flush>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Rate</TableHead>
                            <TableHead className="text-right">VAT %</TableHead>
                            <TableHead className="text-right">Net</TableHead>
                            <TableHead className="text-right">VAT</TableHead>
                            <TableHead className="text-right">Gross</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rates.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={5} className="text-muted-foreground py-6 text-center">
                                    No till sales in this quarter.
                                </TableCell>
                            </TableRow>
                        ) : (
                            rates.map((r) => (
                                <TableRow key={`${r.code}-${r.percentage}`}>
                                    <TableCell className="font-medium">{r.code}</TableCell>
                                    <TableCell className="text-right tabular-nums">{Number(r.percentage)}%</TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={r.net} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={r.vat} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount value={r.gross} />
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </SectionCard>

            {tillReturns.length > 0 && (
                <SectionCard title="VAT returns kept on the tills" description="Returns a shop worked out on its till for these dates, to compare. Read only." flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Shop and period</TableHead>
                                {[1, 3, 4, 5, 6, 7].map((b) => (
                                    <TableHead key={b} className="text-right">
                                        Box {b}
                                    </TableHead>
                                ))}
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tillReturns.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell>
                                        <span className="font-medium">{r.shop ?? 'Unknown shop'}</span>
                                        <p className="text-muted-foreground text-xs">
                                            {formatDay(r.from)} to {formatDay(r.to)}
                                        </p>
                                    </TableCell>
                                    {[0, 2, 3, 4, 5, 6].map((i) => (
                                        <TableCell key={i} className="text-right">
                                            <Amount value={r.boxes[i]} />
                                        </TableCell>
                                    ))}
                                    <TableCell>
                                        {r.filedAt ? (
                                            <StatusPill tone="success">Filed {formatDateTime(r.filedAt)}</StatusPill>
                                        ) : (
                                            <StatusPill tone="neutral">Not filed</StatusPill>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </SectionCard>
            )}
        </AccountsPageLayout>
    );
}
