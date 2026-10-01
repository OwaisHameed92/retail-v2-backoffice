import { ApproveDialog } from '@/components/admin/catalogue/approve-dialog';
import { CatalogueTabs } from '@/components/admin/catalogue/catalogue-tabs';
import { type ContributionProps, type ContributionRow } from '@/components/admin/catalogue/types';
import { formatDateTime } from '@/components/admin/format';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Check, Inbox, ShieldCheck, X } from 'lucide-react';
import { useMemo, useState } from 'react';

const ONLY = ['contributions', 'filters', 'counts'];
const TONES = { pending: 'info', approved: 'success', rejected: 'neutral' } as const;

function columns(onApprove: (row: ContributionRow) => void, onReject: (row: ContributionRow) => void): ColumnDef<ContributionRow>[] {
    return [
        {
            id: 'name',
            header: 'Product',
            enableSorting: true,
            meta: { mobile: 'title' },
            cell: ({ row }) => (
                <div className="min-w-0 leading-tight">
                    <p className="truncate font-medium">{row.original.name}</p>
                    <p className="text-muted-foreground font-mono text-xs tabular-nums">
                        {[row.original.barcode, row.original.size].filter(Boolean).join(' · ')}
                    </p>
                </div>
            ),
        },
        {
            id: 'seen_count',
            header: 'Seen',
            enableSorting: true,
            meta: { align: 'right', mobile: 'aside' },
            cell: ({ row }) => <span className="tabular-nums">{row.original.seen}</span>,
        },
        {
            id: 'created_at',
            header: 'First seen',
            enableSorting: true,
            cell: ({ row }) => <span className="tabular-nums">{formatDateTime(row.original.firstSeenAt, '—')}</span>,
        },
        {
            id: 'status',
            header: 'Status',
            cell: ({ row }) =>
                row.original.masterProductId ? (
                    <Link href={route('admin.catalogue.edit', row.original.masterProductId)} onClick={(e) => e.stopPropagation()}>
                        <StatusBadge status={row.original.status} label={row.original.statusLabel} tone={TONES[row.original.status]} />
                    </Link>
                ) : (
                    <StatusBadge status={row.original.status} label={row.original.statusLabel} tone={TONES[row.original.status]} />
                ),
        },
        {
            id: 'actions',
            header: '',
            meta: { align: 'right', mobile: 'actions' },
            cell: ({ row }) =>
                row.original.status === 'pending' ? (
                    <div className="flex justify-end gap-2">
                        <Button size="sm" variant="ghost" onClick={() => onReject(row.original)}>
                            <X />
                            Reject
                        </Button>
                        <Button size="sm" onClick={() => onApprove(row.original)}>
                            <Check />
                            Review
                        </Button>
                    </div>
                ) : null,
        },
    ];
}

/** Barcodes tills sold that the catalogue does not know yet, collected anonymously, for an admin to approve. */
export default function Contributions({ contributions, filters, counts, options }: ContributionProps) {
    const { update } = useTableQuery({ only: ONLY });
    const [approving, setApproving] = useState<ContributionRow | null>(null);
    const [rejecting, setRejecting] = useState<ContributionRow | null>(null);
    const cols = useMemo(() => columns(setApproving, setRejecting), []);
    const pending = counts.find((c) => c.value === 'pending')?.count ?? 0;

    return (
        <AdminLayout>
            <Head title="Catalogue review queue" />
            <PageHeader
                title="Catalogue"
                description="Barcodes sold on tills that the catalogue does not know yet. Approve the real products to grow the catalogue for every business."
                tabs={<CatalogueTabs pending={pending} />}
            />

            <Alert variant="info">
                <ShieldCheck />
                <AlertDescription>
                    Collected anonymously: barcode, the till&apos;s product name and size only. No prices, businesses, shops or tills are kept.
                    Businesses can opt out.
                </AlertDescription>
            </Alert>

            <PageTabs
                label="Review status"
                value={filters.status}
                onChange={(status) => update({ status, page: undefined })}
                tabs={counts.map((c) => ({ label: c.label, value: c.value, count: c.count }))}
            />

            <DataTable
                columns={cols}
                data={contributions.data}
                meta={contributions.meta}
                only={ONLY}
                searchPlaceholder="Search name or barcode"
                getRowId={(row) => row.id}
                empty={
                    contributions.meta.search ? undefined : (
                        <EmptyState
                            icon={Inbox}
                            title={filters.status === 'pending' ? 'Nothing to review' : 'Nothing here yet'}
                            body="When tills sell a barcode the catalogue does not know, it appears here."
                        />
                    )
                }
            />

            {approving && <ApproveDialog row={approving} options={options} onClose={() => setApproving(null)} />}

            <ConfirmDialog
                open={rejecting !== null}
                onOpenChange={(open) => !open && setRejecting(null)}
                title={`Reject ${rejecting?.barcode ?? 'this barcode'}?`}
                description="It stays out of the catalogue. Later sightings on tills only raise its count."
                confirmLabel="Reject"
                destructive
                onConfirm={() =>
                    new Promise((resolve) =>
                        router.post(
                            route('admin.catalogue.contributions.reject', rejecting?.id ?? ''),
                            {},
                            { preserveScroll: true, onFinish: () => resolve(setRejecting(null)) },
                        ),
                    )
                }
            />
        </AdminLayout>
    );
}
