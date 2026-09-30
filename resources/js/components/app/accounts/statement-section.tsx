import { SectionCard } from '@/components/shared/section-card';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link } from '@inertiajs/react';
import { Amount } from './accounts-page';
import { type AccountsFiltersState, type StatementLine } from './types';

interface Props {
    title: string;
    description?: string;
    lines: StatementLine[];
    total: string;
    totalLabel: string;
    filters: AccountsFiltersState;
    empty?: string;
}

/** One block of a statement (income, assets…): account lines linking to their journals, then the total. */
export function StatementSection({ title, description, lines, total, totalLabel, filters, empty = 'Nothing posted.' }: Props) {
    const journals = (code: string) =>
        route('app.accounts.journals.index', {
            from: filters.from,
            to: filters.to,
            account: code,
            ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }),
        });

    return (
        <SectionCard title={title} description={description} flush>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-24">Code</TableHead>
                        <TableHead>Account</TableHead>
                        <TableHead className="text-right">Amount</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {lines.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={3} className="text-muted-foreground py-6 text-center">
                                {empty}
                            </TableCell>
                        </TableRow>
                    ) : (
                        lines.map((l) => (
                            <TableRow key={l.code}>
                                <TableCell className="font-mono text-xs tabular-nums">{l.code}</TableCell>
                                <TableCell>
                                    {l.code === '—' ? (
                                        <span className="text-muted-foreground">{l.name}</span>
                                    ) : (
                                        <Link href={journals(l.code)} className="hover:underline">
                                            {l.name}
                                        </Link>
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount value={l.amount} />
                                </TableCell>
                            </TableRow>
                        ))
                    )}
                </TableBody>
                <TableFooter>
                    <TableRow>
                        <TableCell colSpan={2} className="font-semibold">
                            {totalLabel}
                        </TableCell>
                        <TableCell className="text-right">
                            <Amount value={total} strong />
                        </TableCell>
                    </TableRow>
                </TableFooter>
            </Table>
        </SectionCard>
    );
}

/** A labelled total line between sections (gross profit, net profit). */
export function StatementTotal({ label, value, hint }: { label: string; value: string; hint?: string }) {
    const n = Number(value);

    return (
        <div className="bg-card flex items-center justify-between rounded-xl border px-5 py-4 sm:px-6">
            <div>
                <p className="font-semibold">{label}</p>
                {hint && <p className="text-muted-foreground text-sm">{hint}</p>}
            </div>
            <Amount value={value} strong className={n < 0 ? 'text-danger-foreground text-lg' : 'text-lg'} />
        </div>
    );
}
