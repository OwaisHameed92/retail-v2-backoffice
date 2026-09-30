import { Amount } from '@/components/app/accounts/accounts-page';
import { JournalFlags } from '@/components/app/accounts/journal-flags';
import { type JournalProps } from '@/components/app/accounts/types';
import { formatDateTime, formatDay } from '@/components/app/pricing/format';
import { DescriptionList } from '@/components/shared/description-list';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Receipt, TriangleAlert } from 'lucide-react';

export default function AccountsJournal({ entry }: JournalProps) {
    const title = `${entry.refType ?? 'Journal'} · ${formatDay(entry.date)}`;

    return (
        <AppLayout>
            <Head title={`${title} · Journals`} />
            <PageHeader
                title={title}
                description={entry.memo ?? 'Journal entry posted by the till.'}
                back={{ href: route('app.accounts.journals.index'), label: 'Journals' }}
                status={<JournalFlags entry={entry} />}
                actions={
                    entry.saleId && (
                        <Button variant="outline" asChild>
                            <Link href={route('app.sales.show', entry.saleId)}>
                                <Receipt />
                                View the sale
                            </Link>
                        </Button>
                    )
                }
            />
            <div className="grid gap-6">
                {entry.oldRefund && (
                    <Alert variant="warning">
                        <TriangleAlert />
                        <AlertTitle>Posted before the refund fix</AlertTitle>
                        <AlertDescription>
                            The till journalled this refund like a sale (before version 0.1.15): sales, VAT and cash went up instead of down. The till does
                            not re-post it. The trial balance, profit and loss and balance sheet can show it turned the right way round.
                        </AlertDescription>
                    </Alert>
                )}
                <SectionCard title="Entry">
                    <DescriptionList
                        items={[
                            { label: 'Date', value: formatDay(entry.date) },
                            { label: 'Type', value: entry.refType },
                            { label: 'Reference', value: entry.refId, mono: true },
                            { label: 'Shop', value: entry.shop },
                            { label: 'Till', value: entry.till },
                            { label: 'Posted', value: entry.postedAt ? formatDateTime(entry.postedAt) : null },
                            { label: 'Posted by', value: entry.postedBy },
                            {
                                label: 'Reversal',
                                value: entry.reversedByEntryId ? (
                                    <Link className="text-primary hover:underline" href={route('app.accounts.journals.show', entry.reversedByEntryId)}>
                                        Reversed by this entry
                                    </Link>
                                ) : entry.reversesEntryId ? (
                                    <Link className="text-primary hover:underline" href={route('app.accounts.journals.show', entry.reversesEntryId)}>
                                        Reverses this entry
                                    </Link>
                                ) : null,
                            },
                        ]}
                    />
                </SectionCard>
                <SectionCard title="Lines" description="Each line debits or credits one account. Debits and credits always add up to the same." flush>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-24">Code</TableHead>
                                <TableHead>Account</TableHead>
                                <TableHead className="text-right">Debit</TableHead>
                                <TableHead className="text-right">Credit</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {entry.lines.map((l) => (
                                <TableRow key={l.id}>
                                    <TableCell className="font-mono text-xs tabular-nums">{l.code}</TableCell>
                                    <TableCell>
                                        <span className="font-medium">{l.name}</span>
                                        {l.memo && <p className="text-muted-foreground text-xs">{l.memo}</p>}
                                    </TableCell>
                                    <TableCell className="text-right">{Number(l.debit ?? 0) !== 0 ? <Amount value={l.debit} /> : null}</TableCell>
                                    <TableCell className="text-right">{Number(l.credit ?? 0) !== 0 ? <Amount value={l.credit} /> : null}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={2} className="font-semibold">
                                    Total
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount value={entry.debits} strong />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount value={entry.credits} strong />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </SectionCard>
            </div>
        </AppLayout>
    );
}
