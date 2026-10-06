import { RequestStatus, RequestType, TillStepList, when } from '@/components/app/privacy/request-parts';
import { RetentionCard } from '@/components/app/privacy/retention-card';
import { type DataRequestRow, type PrivacyIndexProps } from '@/components/app/privacy/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import AppLayout from '@/layouts/app-layout';
import { ukOnly } from '@/lib/country-text';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Info, LockKeyhole } from 'lucide-react';

const ONLY = ['requests', 'filters'];

const columns: ColumnDef<DataRequestRow>[] = [
    {
        id: 'customer',
        header: 'Customer',
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{row.original.customerName}</span>
                <span className="text-muted-foreground text-xs">{row.original.requestedBy}</span>
            </div>
        ),
        meta: { mobile: 'title' },
    },
    { id: 'type', header: 'Request', cell: ({ row }) => <RequestType row={row.original} /> },
    { id: 'status', header: 'Status', cell: ({ row }) => <RequestStatus row={row.original} />, meta: { mobile: 'aside' } },
    {
        id: 'created_at',
        header: 'Requested',
        enableSorting: true,
        cell: ({ row }) => <span className="tabular-nums">{when(row.original.createdAt)}</span>,
    },
    {
        id: 'steps',
        header: 'Till steps',
        cell: ({ row }) => (row.original.tillSteps.length === 0 ? '—' : `${row.original.tillSteps.length} open`),
        meta: { align: 'right' },
    },
];

/** Privacy (module 7.7, owner only): customer data requests, data retention, and what is left to do on the tills. */
export default function PrivacyIndex({ requests, filters, settings, due, pendingCount }: PrivacyIndexProps) {
    const { update } = useTableQuery({ only: ONLY });
    const pending = requests.data.filter((r) => r.status === 'tillPending');

    return (
        <AppLayout>
            <Head title="Privacy" />
            <PageHeader
                title="Privacy"
                description={ukOnly(
                    "Your customers' data rights under UK GDPR: copies of their data, erasure, and how long you keep their details.",
                    "Your customers' data rights: copies of their data, erasure, and how long you keep their details.",
                )}
            />

            <Alert variant="info">
                <Info />
                <AlertTitle>Your business is responsible for your customers' data</AlertTitle>
                <AlertDescription>
                    To give a customer a copy of their data or anonymise them, open the customer and choose the Privacy tab. Every request is recorded
                    here and in your activity log. See our{' '}
                    <Link href={route('legal', 'dpa')} className="underline">
                        data processing agreement
                    </Link>{' '}
                    and{' '}
                    <Link href={route('legal', 'privacy')} className="underline">
                        privacy notice
                    </Link>
                    .
                </AlertDescription>
            </Alert>

            <RetentionCard settings={settings} due={due} />

            {pendingCount > 0 && (
                <SectionCard
                    title="Waiting for the tills"
                    description="These customers are anonymised here and on the tills, but some records kept only on the till still carry their details. Clear them on the till, then mark the request done."
                >
                    {pending.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {pendingCount} {pendingCount === 1 ? 'request is' : 'requests are'} waiting. Filter the list below by status to see them.
                        </p>
                    ) : (
                        <div className="grid gap-4">
                            {pending.map((r) => (
                                <div key={r.id} className="grid gap-2">
                                    <p className="text-sm font-medium">
                                        {r.customerName} · {when(r.createdAt)}
                                    </p>
                                    <TillStepList row={r} />
                                </div>
                            ))}
                        </div>
                    )}
                </SectionCard>
            )}

            <DataTable
                columns={columns}
                data={requests.data}
                meta={requests.meta}
                only={ONLY}
                searchable={false}
                getRowId={(row) => row.id}
                onRowClick={(row) => row.customerExists && row.customerId && router.visit(route('app.customers.show', row.customerId))}
                filters={
                    <>
                        <FilterSelect
                            value={filters.type}
                            onChange={(type) => update({ type, page: undefined })}
                            all="Every request"
                            options={[
                                { value: 'export', label: 'Data export' },
                                { value: 'erasure', label: 'Erasure' },
                            ]}
                            label="Filter by request"
                        />
                        <FilterSelect
                            value={filters.status}
                            onChange={(status) => update({ status, page: undefined })}
                            all="Any status"
                            options={[
                                { value: 'completed', label: 'Completed' },
                                { value: 'tillPending', label: 'Waiting for the tills' },
                            ]}
                            label="Filter by status"
                        />
                    </>
                }
                empty={
                    filters.type || filters.status ? undefined : (
                        <EmptyState
                            icon={LockKeyhole}
                            title="No data requests yet"
                            body="When you download a customer's data or anonymise them, the request is recorded here."
                        />
                    )
                }
            />
        </AppLayout>
    );
}
