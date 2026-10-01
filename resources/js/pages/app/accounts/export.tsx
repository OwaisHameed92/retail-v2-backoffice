import { AccountsFilters, AccountsPageLayout, Amount, RefundFixNotice } from '@/components/app/accounts/accounts-page';
import { type ExportPreviewProps } from '@/components/app/accounts/export-types';
import { formatDay } from '@/components/app/pricing/format';
import { useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link } from '@inertiajs/react';
import { BookOpen, Download, FileSpreadsheet, Info, Settings2, TriangleAlert } from 'lucide-react';

/** Journals for Xero, QuickBooks or Sage (gap #8): choose the package, dates and shop, check the preview, download. */
export default function AccountsExport(props: ExportPreviewProps) {
    const { target, grouping, targets, importHelp, journals, more, totals, unmapped, sample, refundFix, filters, options } = props;
    const { update } = useTableQuery();
    const params = {
        from: filters.from,
        to: filters.to,
        ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }),
        ...(filters.fix ? { fix: '1' } : {}),
        target,
        grouping,
    };
    const label = targets.find((t) => t.value === target)?.label ?? target;

    return (
        <AccountsPageLayout
            tab="export"
            filters={filters}
            title="Export · Accounts"
            description="Your tills' journals as a file your accounting package imports: one balanced journal per shop per day, or per shop for the period."
            actions={
                <>
                    <Button variant="outline" asChild>
                        <Link href={route('app.accounts.export.mappings', { target })}>
                            <Settings2 />
                            Account mapping
                        </Link>
                    </Button>
                    <Button asChild disabled={journals.length === 0}>
                        <a href={route('app.accounts.export.download', params)}>
                            <Download />
                            Download for {label}
                        </a>
                    </Button>
                </>
            }
        >
            <AccountsFilters filters={filters} options={options} update={update}>
                <Select value={target} onValueChange={(value) => update({ target: value })}>
                    <SelectTrigger className="h-9 w-full sm:w-48" aria-label="Accounting package">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {targets.map((t) => (
                            <SelectItem key={t.value} value={t.value}>
                                {t.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={grouping} onValueChange={(value) => update({ grouping: value })}>
                    <SelectTrigger className="h-9 w-full sm:w-52" aria-label="One journal per">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="daily">One journal per shop per day</SelectItem>
                        <SelectItem value="period">One journal per shop for the period</SelectItem>
                    </SelectContent>
                </Select>
            </AccountsFilters>

            <Alert variant="info">
                <Info />
                <AlertTitle>Importing into {label}</AlertTitle>
                <AlertDescription>
                    {importHelp} Codes come from your account mapping; check it with your accountant before the first import.
                </AlertDescription>
            </Alert>
            <RefundFixNotice summary={refundFix} fix={filters.fix} update={update} />
            {unmapped.length > 0 && (
                <Alert variant="warning">
                    <TriangleAlert />
                    <AlertTitle>
                        {unmapped.length === 1 ? '1 account has' : `${unmapped.length} accounts have`} no {label} code yet
                    </AlertTitle>
                    <AlertDescription>
                        <p>
                            {unmapped.map((u) => `${u.code} ${u.name}`).join(', ')} will be exported with our own code, which {label} may not
                            recognise.
                        </p>
                        <Button variant="outline" size="sm" className="mt-2" asChild>
                            <Link href={route('app.accounts.export.mappings', { target })}>Map them</Link>
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            <StatGrid columns={3}>
                <StatCard label="Journals" value={totals.journals} hint={`${totals.lines} lines`} icon={BookOpen} tone="primary" />
                <StatCard label="Debits" value={<Amount value={totals.debits} />} hint="Equal to the credits" tone="neutral" />
                <StatCard
                    label="Credits"
                    value={<Amount value={totals.credits} />}
                    hint={`${formatDay(filters.from)} – ${formatDay(filters.to)}`}
                    tone="neutral"
                />
            </StatGrid>

            {journals.length === 0 ? (
                <SectionCard>
                    <EmptyState
                        icon={FileSpreadsheet}
                        title="Nothing to export"
                        body="No till posted journals in these dates. Try other dates or another shop."
                    />
                </SectionCard>
            ) : (
                <>
                    {journals.map((j) => (
                        <SectionCard
                            key={`${j.ref}-${j.shop}`}
                            title={j.narration}
                            description={`Reference ${j.ref} · dated ${formatDay(j.date)}`}
                            flush
                        >
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-40">Our account</TableHead>
                                        <TableHead>{label} code</TableHead>
                                        <TableHead className="hidden md:table-cell">Tax code</TableHead>
                                        <TableHead className="text-right">Debit</TableHead>
                                        <TableHead className="text-right">Credit</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {j.lines.map((l) => (
                                        <TableRow key={`${l.ourCode}-${l.vatCode ?? ''}`}>
                                            <TableCell>
                                                <span className="font-mono text-xs tabular-nums">{l.ourCode}</span>
                                                <p className="text-muted-foreground text-xs">{l.name}</p>
                                            </TableCell>
                                            <TableCell>
                                                {l.mapped ? (
                                                    <span className="font-mono text-xs">{l.theirCode}</span>
                                                ) : (
                                                    <StatusPill tone="warning">Not mapped</StatusPill>
                                                )}
                                            </TableCell>
                                            <TableCell className="hidden text-xs md:table-cell">{l.taxCode}</TableCell>
                                            <TableCell className="text-right">{Number(l.debit) !== 0 && <Amount value={l.debit} />}</TableCell>
                                            <TableCell className="text-right">{Number(l.credit) !== 0 && <Amount value={l.credit} />}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell colSpan={3} className="font-semibold">
                                            Total
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount value={j.debits} strong />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount value={j.credits} strong />
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </SectionCard>
                    ))}
                    {more > 0 && (
                        <p className="text-muted-foreground text-center text-sm">
                            And {more} more {more === 1 ? 'journal' : 'journals'} in the download.
                        </p>
                    )}
                    <SectionCard title="The file's first rows" description={`Exactly as written to the ${label} CSV.`} flush>
                        <div className="overflow-x-auto">
                            <table className="w-full font-mono text-xs">
                                <thead>
                                    <tr className="bg-muted/50">
                                        {sample.header.map((h) => (
                                            <th key={h} className="border-border border-b px-3 py-2 text-left font-medium whitespace-nowrap">
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {sample.rows.map((row, i) => (
                                        <tr key={i} className="border-border border-b last:border-0">
                                            {row.map((cell, k) => (
                                                <td key={k} className="px-3 py-1.5 whitespace-nowrap">
                                                    {cell}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </SectionCard>
                </>
            )}
        </AccountsPageLayout>
    );
}
