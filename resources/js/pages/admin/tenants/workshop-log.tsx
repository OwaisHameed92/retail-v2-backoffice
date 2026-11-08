import { formatDateTimeShort } from '@/components/admin/tenants/format';
import { type Tenant } from '@/components/admin/tenants/types';
import { WORKSHOP_STATUS_TONES, type WorkshopBranchCard, type WorkshopCallRow, type WorkshopDeliveryRow } from '@/components/admin/workshop/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Download, RotateCcw, Webhook, Wrench } from 'lucide-react';

interface Props {
    tenant: Tenant;
    card: WorkshopBranchCard;
    tab: 'calls' | 'deliveries';
    rows: Paginated<WorkshopCallRow> | Paginated<WorkshopDeliveryRow>;
    canManage: boolean;
}

const DELIVERY_TONES = { queued: 'neutral', retrying: 'warning', delivered: 'success', failed: 'danger' } as const;

function callColumns(): ColumnDef<WorkshopCallRow>[] {
    return [
        { accessorKey: 'createdAt', header: 'Time', enableSorting: true, cell: ({ row }) => formatDateTimeShort(row.original.createdAt) },
        {
            accessorKey: 'path',
            header: 'Call',
            cell: ({ row }) => (
                <span className="font-mono text-[13px] break-all">
                    {row.original.method} {row.original.path}
                </span>
            ),
        },
        {
            accessorKey: 'status',
            header: 'Result',
            enableSorting: true,
            cell: ({ row }) => (
                <StatusBadge
                    status={String(row.original.status)}
                    label={`${row.original.status}${row.original.error ? ` ${row.original.error}` : ''}`}
                    tone={row.original.status < 400 ? 'success' : row.original.status < 500 ? 'warning' : 'danger'}
                />
            ),
        },
        {
            accessorKey: 'durationMs',
            header: 'ms',
            enableSorting: true,
            meta: { align: 'right' },
            cell: ({ row }) => row.original.durationMs.toLocaleString('en-GB'),
        },
        { accessorKey: 'auth', header: 'Sign-in', cell: ({ row }) => (row.original.auth === 'oauth' ? 'OAuth' : 'API key') },
        {
            accessorKey: 'requestId',
            header: 'BikeBook request',
            cell: ({ row }) => <span className="font-mono text-[13px]">{row.original.requestId ?? '—'}</span>,
        },
    ];
}

function deliveryColumns(tenantId: string, branchId: string, canReplay: boolean): ColumnDef<WorkshopDeliveryRow>[] {
    return [
        { accessorKey: 'createdAt', header: 'Time', enableSorting: true, cell: ({ row }) => formatDateTimeShort(row.original.createdAt) },
        { accessorKey: 'event', header: 'Event', cell: ({ row }) => <span className="font-mono text-[13px]">{row.original.event}</span> },
        { accessorKey: 'orderId', header: 'Order', cell: ({ row }) => <span className="font-mono text-[13px]">{row.original.orderId ?? '—'}</span> },
        {
            accessorKey: 'state',
            header: 'Status',
            meta: { mobile: 'aside' },
            cell: ({ row }) => <StatusBadge status={row.original.state} tone={DELIVERY_TONES[row.original.state]} />,
        },
        { accessorKey: 'attempts', header: 'Attempts', enableSorting: true, meta: { align: 'right' } },
        {
            accessorKey: 'lastError',
            header: 'Error',
            cell: ({ row }) => (
                <span className="text-muted-foreground text-sm">
                    {row.original.lastError ??
                        (row.original.nextAttemptAt && row.original.state !== 'delivered'
                            ? `Next try ${formatDateTimeShort(row.original.nextAttemptAt)}`
                            : '—')}
                </span>
            ),
        },
        {
            id: 'actions',
            header: '',
            meta: { mobile: 'actions' },
            cell: ({ row }) =>
                canReplay && (row.original.state === 'failed' || row.original.state === 'delivered') ? (
                    <ConfirmDialog
                        trigger={
                            <Button size="sm" variant="outline">
                                <RotateCcw aria-hidden />
                                Replay
                            </Button>
                        }
                        title="Send this webhook to BikeBook again?"
                        description="The same body and event id go again now. BikeBook ignores an event it already has."
                        confirmLabel="Replay"
                        onConfirm={() =>
                            new Promise<void>((resolve) =>
                                router.post(
                                    route('admin.tenants.branches.workshop.replay', [tenantId, branchId, row.original.id]),
                                    {},
                                    { preserveScroll: true, onFinish: () => resolve() },
                                ),
                            )
                        }
                    />
                ) : null,
        },
    ];
}

/** W5: a shop's BikeBook sync log: the last 1,000 calls and the webhook deliveries with Replay, CSV export. */
export default function WorkshopLog({ tenant, card, tab, rows, canManage }: Props) {
    const params = [tenant.id, card.branchId];
    const exportHref = route('admin.tenants.branches.workshop.export', params) + (tab === 'deliveries' ? '?kind=deliveries' : '');

    return (
        <AdminLayout>
            <Head title={`BikeBook log · ${card.branchName}`} />
            <PageHeader
                title={`BikeBook sync log · ${card.branchName}`}
                status={<StatusBadge status={card.status} label={card.statusLabel} tone={WORKSHOP_STATUS_TONES[card.status]} />}
                back={{ href: `${route('admin.tenants.show', tenant.id)}?tab=workshop`, label: tenant.name }}
                description="The last 1,000 calls BikeBook made and the webhooks we sent it. No keys, tokens, bodies or addresses are kept."
                actions={
                    <Button asChild variant="outline">
                        <a href={exportHref}>
                            <Download aria-hidden />
                            Export CSV
                        </a>
                    </Button>
                }
                tabs={
                    <PageTabs
                        label="Log sections"
                        tabs={[
                            { label: 'Calls from BikeBook', href: route('admin.tenants.branches.workshop.log', params), active: tab === 'calls' },
                            {
                                label: 'Webhooks to BikeBook',
                                href: `${route('admin.tenants.branches.workshop.log', params)}?tab=deliveries`,
                                active: tab === 'deliveries',
                            },
                        ]}
                    />
                }
            />

            {tab === 'calls' ? (
                <DataTable
                    columns={callColumns()}
                    data={(rows as Paginated<WorkshopCallRow>).data}
                    meta={rows.meta}
                    only={['rows']}
                    searchPlaceholder="Search call or request id"
                    getRowId={(row) => String(row.id)}
                    empty={<EmptyState icon={Wrench} title="No calls yet" body="BikeBook's calls appear here once it is connected." />}
                />
            ) : (
                <DataTable
                    columns={deliveryColumns(tenant.id, card.branchId, canManage)}
                    data={(rows as Paginated<WorkshopDeliveryRow>).data}
                    meta={rows.meta}
                    only={['rows']}
                    searchPlaceholder="Search event, order or event id"
                    getRowId={(row) => row.id}
                    empty={
                        <EmptyState
                            icon={Webhook}
                            title="No webhooks yet"
                            body="Webhooks go to BikeBook when a connector order is paid, cancelled or refunded, and when stock changes."
                        />
                    }
                />
            )}
        </AdminLayout>
    );
}
