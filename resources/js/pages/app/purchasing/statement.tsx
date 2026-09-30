import { documentHref, formatDay, money, PAYMENT_METHODS } from '@/components/app/purchasing/format';
import { type StatementEntry, type StatementProps } from '@/components/app/purchasing/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { Banknote, FileText, Printer, ReceiptText, Scale } from 'lucide-react';
import { useState } from 'react';

const TYPES: Record<StatementEntry['type'], { label: string; tone: StatusTone }> = {
    invoice: { label: 'Invoice', tone: 'neutral' },
    credit: { label: 'Credit note', tone: 'success' },
    payment: { label: 'Payment', tone: 'info' },
};

const amount = (value: string) => (Number(value) === 0 ? <span className="text-muted-foreground">—</span> : money(value));

/** One supplier's statement for a period (module 5.2): opening balance, each document with a running balance, closing balance. */
export default function SupplierStatementShow({ supplier, period, opening, entries, totals, closing, shop, shops, oneShop }: StatementProps) {
    const [from, setFrom] = useState(period.from);
    const [to, setTo] = useState(period.to);
    const shopName = (id: string | null) => shops.find((s) => s.id === id)?.name ?? null;
    const reload = (next: { shop?: string | null }) => {
        const pick = next.shop === undefined ? shop : next.shop;
        router.get(
            route('app.purchasing.statements.show', supplier.id),
            { from, to, ...(pick ? { shop: pick } : {}) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout>
            <Head title={`${supplier.name} statement`} />

            <PageHeader
                title={supplier.name}
                back={{ href: route('app.purchasing.statements.index', shop && !oneShop ? { shop } : {}), label: 'Statements' }}
                description={[
                    `Statement ${formatDay(period.from)} – ${formatDay(period.to)}`,
                    shop ? shopName(shop) : 'Every shop',
                    supplier.account ? `Account ${supplier.account}` : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    <Button variant="outline" onClick={() => window.print()}>
                        <Printer />
                        Print
                    </Button>
                }
            />

            <SectionCard>
                <form
                    className="flex flex-col gap-3 sm:flex-row sm:items-end"
                    onSubmit={(e) => {
                        e.preventDefault();
                        reload({});
                    }}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} className="sm:w-44" />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} className="sm:w-44" />
                    </div>
                    {!oneShop && shops.length > 1 && (
                        <div className="grid gap-1.5">
                            <Label>Shop</Label>
                            <FilterSelect
                                value={shop}
                                onChange={(next) => reload({ shop: next ?? null })}
                                all="Every shop"
                                options={shops.map((s) => ({ value: s.id, label: s.name }))}
                                label="Filter by shop"
                            />
                        </div>
                    )}
                    <Button type="submit" variant="secondary">
                        Show statement
                    </Button>
                </form>
            </SectionCard>

            <StatGrid>
                <StatCard label="Invoiced" value={money(totals.invoiced)} icon={FileText} tone="neutral" hint="In this period" />
                <StatCard label="Credited" value={money(totals.credited)} icon={ReceiptText} tone="success" hint="In this period" />
                <StatCard label="Paid" value={money(totals.paid)} icon={Banknote} tone="primary" hint="In this period" />
                <StatCard
                    label="Balance owed"
                    value={money(closing)}
                    icon={Scale}
                    tone={Number(closing) > 0 ? 'warning' : 'success'}
                    hint={`At ${formatDay(period.to)}`}
                />
            </StatGrid>

            <SectionCard title="Statement" description="Invoices add to what you owe; credit notes and payments take it off." flush>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Document</TableHead>
                            <TableHead className="text-right">Invoiced</TableHead>
                            <TableHead className="text-right">Credit / paid</TableHead>
                            <TableHead className="text-right">Balance</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow className="bg-muted/40">
                            <TableCell>{formatDay(period.from)}</TableCell>
                            <TableCell className="font-medium">Opening balance</TableCell>
                            <TableCell />
                            <TableCell />
                            <TableCell className="text-right font-medium tabular-nums">{money(opening)}</TableCell>
                        </TableRow>
                        {entries.map((e) => {
                            const href = e.kind ? documentHref(e.kind, e.id) : null;
                            const detail =
                                e.type === 'payment'
                                    ? e.detail
                                        ? (PAYMENT_METHODS[e.detail] ?? e.detail)
                                        : null
                                    : e.type === 'invoice' && e.detail
                                      ? `Due ${formatDay(e.detail)}`
                                      : e.detail;

                            return (
                                <TableRow key={`${e.type}-${e.id}`}>
                                    <TableCell className="whitespace-nowrap">{formatDay(e.date)}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusPill tone={TYPES[e.type].tone}>{TYPES[e.type].label}</StatusPill>
                                            {href ? (
                                                <Link href={href} className="font-mono text-sm font-medium hover:underline">
                                                    {e.reference}
                                                </Link>
                                            ) : (
                                                <span className="font-mono text-sm font-medium">{e.reference}</span>
                                            )}
                                        </div>
                                        {(detail || (!shop && shopName(e.shopId))) && (
                                            <p className="text-muted-foreground mt-0.5 text-xs">
                                                {[detail, !shop ? shopName(e.shopId) : null].filter(Boolean).join(' · ')}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{amount(e.debit)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{amount(e.credit)}</TableCell>
                                    <TableCell className={cn('text-right tabular-nums', Number(e.balance) < 0 && 'text-success-foreground')}>
                                        {money(e.balance)}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={2} className="font-medium">
                                Closing balance
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{money(totals.invoiced)}</TableCell>
                            <TableCell className="text-right tabular-nums">{money(totals.reduced)}</TableCell>
                            <TableCell className="text-right font-semibold tabular-nums">{money(closing)}</TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
                {entries.length === 0 && (
                    <EmptyState
                        icon={Scale}
                        title="Nothing in this period"
                        body="Pick other dates to see earlier invoices, credits and payments."
                        className="py-8"
                    />
                )}
            </SectionCard>
        </AppLayout>
    );
}
