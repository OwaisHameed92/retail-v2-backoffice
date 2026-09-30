import { AccountsFilters, AccountsPageLayout, Amount, RefundFixNotice, TYPE_LABELS } from '@/components/app/accounts/accounts-page';
import { type TrialBalanceProps } from '@/components/app/accounts/types';
import { formatDay } from '@/components/app/pricing/format';
import { useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link } from '@inertiajs/react';
import { CircleCheck, Scale, TriangleAlert } from 'lucide-react';

export default function AccountsTrialBalance({ rows, totals, balanced, refundFix, filters, options }: TrialBalanceProps) {
    const { update } = useTableQuery();
    const journals = (code: string) =>
        route('app.accounts.journals.index', { from: filters.from, to: filters.to, account: code, ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }) });

    return (
        <AccountsPageLayout
            tab="trial"
            filters={filters}
            title="Trial balance · Accounts"
            description="Every account's debits and credits in the period, and its balance at the end date. The two sides always agree."
        >
            <AccountsFilters filters={filters} options={options} update={update} />
            <RefundFixNotice summary={refundFix} fix={filters.fix} update={update} />
            {rows.length > 0 &&
                (balanced ? (
                    <Alert variant="success">
                        <CircleCheck />
                        <AlertTitle>The trial balance balances</AlertTitle>
                        <AlertDescription>Debits and credits agree for the period and at {formatDay(filters.to)}.</AlertDescription>
                    </Alert>
                ) : (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>The trial balance is out by <Amount value={totals.difference} /></AlertTitle>
                        <AlertDescription>
                            Some journal lines may not have synced yet. Wait for every till to sync, then check again; if it stays out, contact support.
                        </AlertDescription>
                    </Alert>
                ))}
            <SectionCard flush>
                {rows.length === 0 ? (
                    <EmptyState icon={Scale} title="Nothing posted yet" body="The trial balance appears once a till has posted journals up to this date." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-24">Code</TableHead>
                                <TableHead>Account</TableHead>
                                <TableHead className="hidden text-right md:table-cell">Period debits</TableHead>
                                <TableHead className="hidden text-right md:table-cell">Period credits</TableHead>
                                <TableHead className="text-right">Debit balance</TableHead>
                                <TableHead className="text-right">Credit balance</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((r) => (
                                <TableRow key={r.code}>
                                    <TableCell className="font-mono text-xs tabular-nums">{r.code}</TableCell>
                                    <TableCell>
                                        <Link href={journals(r.code)} className="font-medium hover:underline">
                                            {r.name}
                                        </Link>
                                        <p className="text-muted-foreground text-xs">{TYPE_LABELS[r.type] ?? r.type}</p>
                                    </TableCell>
                                    <TableCell className="hidden text-right md:table-cell">
                                        <Amount value={r.periodDebit} />
                                    </TableCell>
                                    <TableCell className="hidden text-right md:table-cell">
                                        <Amount value={r.periodCredit} />
                                    </TableCell>
                                    <TableCell className="text-right">{r.debit && <Amount value={r.debit} />}</TableCell>
                                    <TableCell className="text-right">{r.credit && <Amount value={r.credit} />}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={2} className="font-semibold">
                                    Total
                                </TableCell>
                                <TableCell className="hidden text-right md:table-cell">
                                    <Amount value={totals.periodDebit} strong />
                                </TableCell>
                                <TableCell className="hidden text-right md:table-cell">
                                    <Amount value={totals.periodCredit} strong />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount value={totals.debit} strong />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount value={totals.credit} strong />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                )}
            </SectionCard>
        </AccountsPageLayout>
    );
}
