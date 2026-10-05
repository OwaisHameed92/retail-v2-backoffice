import { BalanceText, SignedAmount, money, number } from '@/components/app/customers/format';
import { type StatementProps } from '@/components/app/customers/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormField } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { londonDateTime } from '@/components/till-health/format';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDownToLine, ArrowUpFromLine, Coins, Download, Mail, Scale, Wallet } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

export default function CustomerStatementPage({ statement, canEmail }: StatementProps) {
    const s = statement;
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [range, setRange] = useState({ from: s.from, to: s.to });
    const [loading, setLoading] = useState(false);
    const params = { customer: s.customer.id, from: s.from, to: s.to };
    const closing = Number(s.closing.balance);

    const show: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('app.customers.statement', s.customer.id), range, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    return (
        <AppLayout>
            <Head title={`Statement · ${s.customer.name}`} />
            <PageHeader
                title="Account statement"
                description={`${s.customer.name}${s.customer.cardNo ? ` · Card ${s.customer.cardNo}` : ''} · ${s.period}`}
                back={{ href: route('app.customers.show', s.customer.id), label: s.customer.name }}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={route('app.customers.statement.pdf', params)}>
                                <Download />
                                Download PDF
                            </a>
                        </Button>
                        {canEmail && (
                            <ConfirmDialog
                                trigger={
                                    <Button>
                                        <Mail />
                                        Email to customer
                                    </Button>
                                }
                                title={`Email this statement to ${s.customer.name}?`}
                                description={`We send the ${s.period} statement as a PDF to ${s.customer.email}. Replies go to your business email.`}
                                confirmLabel="Send statement"
                                onConfirm={() =>
                                    new Promise((resolve) =>
                                        router.post(
                                            route('app.customers.statement.email', params),
                                            {},
                                            { preserveScroll: true, onFinish: () => resolve(null) },
                                        ),
                                    )
                                }
                            />
                        )}
                    </>
                }
            />

            <SectionCard title="Period" description="London dates, inclusive. Up to two years at a time.">
                <form onSubmit={show} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <FormField id="from" label="From" error={errors.from} className="sm:w-48">
                        <Input
                            id="from"
                            type="date"
                            value={range.from}
                            max={range.to}
                            onChange={(e) => setRange({ ...range, from: e.target.value })}
                        />
                    </FormField>
                    <FormField id="to" label="To" error={errors.to} className="sm:w-48">
                        <Input id="to" type="date" value={range.to} min={range.from} onChange={(e) => setRange({ ...range, to: e.target.value })} />
                    </FormField>
                    <Button type="submit" variant="outline" disabled={loading}>
                        Show statement
                    </Button>
                </form>
                {errors.email && <p className="text-destructive mt-3 text-sm">{errors.email}</p>}
            </SectionCard>

            <StatGrid columns={4}>
                <StatCard
                    label="Opening balance"
                    value={money(s.opening.balance)}
                    hint={`${number(s.opening.points)} points`}
                    icon={Scale}
                    tone="neutral"
                />
                <StatCard label="Account sales" value={money(s.totals.charges)} hint="Added to what they owe" icon={ArrowUpFromLine} tone="warning" />
                <StatCard
                    label="Payments and credits"
                    value={money(s.totals.credits)}
                    hint="Taken off what they owe"
                    icon={ArrowDownToLine}
                    tone="success"
                />
                <StatCard
                    label={closing > 0 ? 'Amount owed' : closing < 0 ? 'Credit held' : 'Closing balance'}
                    value={money(Math.abs(closing))}
                    hint={`${number(s.closing.points)} points (${number(s.totals.pointsEarned)} earned, ${number(s.totals.pointsUsed)} used)`}
                    icon={closing > 0 ? Wallet : Coins}
                    tone={closing > 0 ? 'warning' : 'success'}
                />
            </StatGrid>

            <SectionCard title="Activity" description={`Every shop's account rows from ${s.period}.`} flush>
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Details</TableHead>
                                <TableHead>Shop</TableHead>
                                <TableHead className="text-right">Amount</TableHead>
                                <TableHead className="text-right">Balance</TableHead>
                                <TableHead className="text-right">Points</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow className="bg-subtle font-medium">
                                <TableCell colSpan={4}>Brought forward</TableCell>
                                <TableCell className="text-right">
                                    <BalanceText balance={s.opening.balance} short />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">{number(s.opening.points)}</TableCell>
                            </TableRow>
                            {s.rows.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-muted-foreground py-8 text-center">
                                        No account activity in this period.
                                    </TableCell>
                                </TableRow>
                            )}
                            {s.rows.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell className="whitespace-nowrap">{londonDateTime(row.at)}</TableCell>
                                    <TableCell>
                                        <div className="grid leading-5">
                                            <span>{row.typeLabel}</span>
                                            {row.note && <span className="text-muted-foreground text-xs">{row.note}</span>}
                                        </div>
                                    </TableCell>
                                    <TableCell>{row.shop}</TableCell>
                                    <TableCell className="text-right">
                                        <SignedAmount value={row.amount} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {row.balanceAfter !== null && <BalanceText balance={row.balanceAfter} short />}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <SignedAmount value={row.points} points />
                                    </TableCell>
                                </TableRow>
                            ))}
                            <TableRow className="bg-subtle font-semibold">
                                <TableCell colSpan={4}>Closing balance</TableCell>
                                <TableCell className="text-right">
                                    <BalanceText balance={s.closing.balance} short />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">{number(s.closing.points)}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </SectionCard>
        </AppLayout>
    );
}
