import { AccountsFilters, AccountsPageLayout, Amount } from '@/components/app/accounts/accounts-page';
import { type ChartProps, type ChartRow } from '@/components/app/accounts/types';
import { useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { hasVatReturn, taxText } from '@/lib/country';
import { Link } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';

const GROUPS: { type: ChartRow['type']; title: string; description: string }[] = [
    { type: 'asset', title: 'Assets', description: 'Cash, bank, stock and what others owe you. Balance on the debit side.' },
    { type: 'liability', title: 'Liabilities', description: 'VAT, deposits held and what you owe. Balance on the credit side.' },
    { type: 'equity', title: 'Capital', description: "The owner's money in the business." },
    { type: 'income', title: 'Income', description: 'Sales by VAT treatment and other income.' },
    { type: 'expense', title: 'Costs', description: 'Cost of sales and overheads.' },
];

export default function AccountsChart({ accounts, filters, options, shopCount }: ChartProps) {
    const { update } = useTableQuery({ only: ['accounts', 'types', 'shopCount', 'filters', 'options'] });
    const journals = (code: string) =>
        route('app.accounts.journals.index', { from: filters.from, to: filters.to, account: code, ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }) });

    return (
        <AccountsPageLayout
            tab="chart"
            filters={filters}
            title="Chart of accounts · Accounts"
            description="Every account your tills post to, one line per code for the whole business. Accounts are set up on the tills."
        >
            <AccountsFilters filters={filters} options={options} update={update} />
            {accounts.length === 0 ? (
                <SectionCard>
                    <EmptyState
                        icon={BookOpen}
                        title="No accounts yet"
                        body="The chart of accounts appears once a till with the accounts feature has synced."
                    />
                </SectionCard>
            ) : (
                GROUPS.map((g) => {
                    const rows = accounts.filter((a) => a.type === g.type);

                    return (
                        rows.length > 0 && (
                            <SectionCard key={g.type} title={g.title} description={taxText(g.description)} flush>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-24">Code</TableHead>
                                            <TableHead>Account</TableHead>
                                            {/* Pakistan plan P9: the VAT return box (HMRC's 9 boxes) only where the VAT return exists. */}
                                            {hasVatReturn() && <TableHead className="hidden md:table-cell">{taxText('VAT box')}</TableHead>}
                                            <TableHead className="hidden md:table-cell">Shops</TableHead>
                                            <TableHead className="text-right">Movement in period</TableHead>
                                            <TableHead className="text-right">Balance at {filters.to.split('-').reverse().join('/')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.map((a) => (
                                            <TableRow key={a.code}>
                                                <TableCell className="font-mono text-xs tabular-nums">{a.code}</TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {a.hasLines ? (
                                                            <Link href={journals(a.code)} className="font-medium hover:underline">
                                                                {a.name}
                                                            </Link>
                                                        ) : (
                                                            <span className="font-medium">{a.name}</span>
                                                        )}
                                                        {a.isSystem && <StatusPill tone="neutral">System</StatusPill>}
                                                        {!a.isActive && <StatusPill tone="warning">Inactive</StatusPill>}
                                                    </div>
                                                    {a.parentCode && <p className="text-muted-foreground text-xs">Under {a.parentCode}</p>}
                                                </TableCell>
                                                {hasVatReturn() && <TableCell className="hidden tabular-nums md:table-cell">{a.vatBox ?? '—'}</TableCell>}
                                                <TableCell className="text-muted-foreground hidden tabular-nums md:table-cell">
                                                    {a.shops === 0 ? 'Journals only' : `${a.shops} of ${shopCount}`}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Amount value={a.movement} />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Amount value={a.balance} strong />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </SectionCard>
                        )
                    );
                })
            )}
        </AccountsPageLayout>
    );
}
