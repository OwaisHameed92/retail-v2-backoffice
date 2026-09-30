import { FilterSelect } from '@/components/app/setup/fields';
import { difference, formatDateTime, pounds } from '@/components/app/pricing/format';
import { PricingTabs } from '@/components/app/pricing/pricing-tabs';
import { type PriceChangeRow, type PriceChangesProps } from '@/components/app/pricing/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Eye, ListChecks } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['batches', 'filters'];
const STATUSES = ['draft', 'approved', 'scheduled', 'live', 'cancelled'].map((s) => ({ value: s, label: s[0].toUpperCase() + s.slice(1) }));

export default function PriceChanges({ batches, filters, preview }: PriceChangesProps) {
    const { update } = useTableQuery({ only: ONLY });
    const open = (id: string | null) => router.reload({ only: ['preview'], data: { batch: id ?? undefined } });

    const columns = useMemo<ColumnDef<PriceChangeRow>[]>(
        () => [
            {
                id: 'reference',
                header: 'Batch',
                enableSorting: true,
                cell: ({ row }) => (
                    <div className="grid leading-5">
                        <span className="font-medium">{row.original.name || row.original.reference}</span>
                        <span className="text-muted-foreground font-mono text-xs">{row.original.reference}</span>
                    </div>
                ),
            },
            { id: 'shop', header: 'Shop', cell: ({ row }) => row.original.shop ?? '—' },
            { id: 'lines', header: 'Products', cell: ({ row }) => row.original.lines, meta: { align: 'right' } },
            { id: 'effective_at', header: 'Goes live', enableSorting: true, cell: ({ row }) => formatDateTime(row.original.effectiveAt) },
            { id: 'status', header: 'Status', cell: ({ row }) => (row.original.status ? <StatusBadge status={row.original.status} tones={{ live: 'success', scheduled: 'info' }} /> : '—') },
            { id: 'created_at', header: 'Made', enableSorting: true, cell: ({ row }) => formatDateTime(row.original.createdAt) },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Price changes" />
            <PageHeader title="Prices" description="Price change batches made at your shops' tills." tabs={<PricingTabs current="changes" />} />

            <Alert variant="info">
                <Eye />
                <AlertDescription>These batches belong to the shop that made them: you can look at them here, and they are approved and applied on its till.</AlertDescription>
            </Alert>

            <DataTable
                columns={columns}
                data={batches.data}
                meta={batches.meta}
                only={ONLY}
                searchPlaceholder="Search by name, reference or reason"
                filters={
                    <FilterSelect value={filters.status} onChange={(status) => update({ status, page: 1 })} all="Any status" options={STATUSES} label="Filter by status" />
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => open(row.id)}
                empty={
                    batches.meta.search || filters.status ? undefined : (
                        <EmptyState icon={ListChecks} title="No price changes yet" body="When a shop prepares a price change batch on its till, it appears here after the till syncs." />
                    )
                }
            />

            <Dialog open={preview !== null} onOpenChange={(value) => !value && open(null)}>
                <DialogContent className="sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{preview?.name || preview?.reference}</DialogTitle>
                        <DialogDescription>
                            {preview?.shop ?? 'A shop'} · {preview?.lines.length ?? 0} product{preview?.lines.length === 1 ? '' : 's'}
                            {preview?.reason ? ` · ${preview.reason}` : ''}
                            {preview?.limited ? ' · first 500 shown' : ''}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="max-h-[60vh] overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Product</TableHead>
                                    <TableHead className="text-right">Old</TableHead>
                                    <TableHead className="text-right">New</TableHead>
                                    <TableHead>Where</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {preview?.lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell className="font-medium">{line.product}</TableCell>
                                        <TableCell className="text-muted-foreground text-right tabular-nums">{pounds(line.oldPrice)}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <div className="grid leading-5">
                                                <span className="font-medium">{pounds(line.newPrice)}</span>
                                                <span className="text-muted-foreground text-xs">{difference(line.newPrice, line.oldPrice) ?? 'No change'}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell>{line.where ?? 'Every shop'}</TableCell>
                                        <TableCell>{line.status ? <StatusBadge status={line.status} tones={{ applied: 'success', skipped: 'neutral' }} /> : '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
