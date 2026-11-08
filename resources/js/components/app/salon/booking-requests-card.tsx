import {
    BOOKING_KIND_LABELS,
    BOOKING_STATUS_LABELS,
    BOOKING_STATUS_TONES,
    type BookingRequestRow,
    type BookingStatus,
    type SalonPageProps,
} from '@/components/app/salon/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatusBadge } from '@/components/shared/status-badge';
import { formatDateTime } from '@/lib/country';
import { type ColumnDef } from '@tanstack/react-table';
import { Inbox } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['requests', 'requestFilters', 'stats'];

interface BookingRequestsCardProps {
    requests: SalonPageProps['requests'];
    status: BookingStatus | null;
    statuses: BookingStatus[];
    showShop: boolean;
}

/** Online booking requests, read only: the till decides each one (accept or decline); the portal only shows them. */
export function BookingRequestsCard({ requests, status, statuses, showShop }: BookingRequestsCardProps) {
    const { update } = useTableQuery({ only: ONLY });
    const filtered = Boolean(requests.meta.search) || status !== null;

    const columns = useMemo<ColumnDef<BookingRequestRow>[]>(
        () => [
            {
                id: 'reference',
                header: 'Reference',
                cell: ({ row }) => (
                    <div className="grid leading-5">
                        <span className="font-mono text-sm font-medium">{row.original.reference || '—'}</span>
                        <span className="text-muted-foreground text-xs">
                            {row.original.kind ? BOOKING_KIND_LABELS[row.original.kind] : 'Request'}
                        </span>
                    </div>
                ),
            },
            {
                id: 'client',
                header: 'Client',
                cell: ({ row }) => row.original.clientFirstName || <span className="text-muted-foreground">—</span>,
            },
            {
                id: 'requested_starts_at',
                header: 'Time asked for',
                enableSorting: true,
                cell: ({ row }) => <span className="tabular-nums">{formatDateTime(row.original.requestedStartsAt)}</span>,
            },
            ...(showShop
                ? [{ id: 'shop', header: 'Shop', cell: ({ row }) => row.original.shopName ?? '—' } satisfies ColumnDef<BookingRequestRow>]
                : []),
            {
                id: 'created_at',
                header: 'Sent',
                enableSorting: true,
                cell: ({ row }) => <span className="text-muted-foreground tabular-nums">{formatDateTime(row.original.createdAt)}</span>,
            },
            {
                id: 'status',
                header: 'Status',
                cell: ({ row }) => (
                    <StatusBadge
                        status={row.original.status}
                        label={BOOKING_STATUS_LABELS[row.original.status] ?? row.original.status}
                        tone={BOOKING_STATUS_TONES[row.original.status] ?? 'neutral'}
                    />
                ),
            },
        ],
        [showShop],
    );

    return (
        <section aria-labelledby="salon-requests-title" className="grid gap-3">
            <div className="grid gap-1">
                <h2 id="salon-requests-title" className="text-base font-semibold">
                    Online booking requests
                </h2>
                <p className="text-muted-foreground text-sm">
                    Requests clients sent from your booking page. The shop’s till accepts or declines each one; this list is read only.
                </p>
            </div>
            <DataTable
                columns={columns}
                data={requests.data}
                meta={requests.meta}
                only={ONLY}
                searchPlaceholder="Search reference or first name"
                filters={
                    <FilterSelect
                        value={status}
                        onChange={(next) => update({ status: next, page: 1 })}
                        all="Every status"
                        options={statuses.map((value) => ({ value, label: BOOKING_STATUS_LABELS[value] }))}
                        label="Filter by status"
                        width="sm:w-48"
                    />
                }
                getRowId={(row) => row.id}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={Inbox}
                            title="No online booking requests yet"
                            body="When clients book on your online booking page, their requests appear here with what the till decided."
                            size="sm"
                        />
                    )
                }
            />
        </section>
    );
}
