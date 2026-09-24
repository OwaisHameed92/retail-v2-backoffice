import { EntityCell, InitialsAvatar } from '@/components/shared/entity-cell';
import { type ColumnDef } from '@tanstack/react-table';
import { FollowUp } from './follow-up';
import { formatDate, leadSourceLabels, plural } from './format';
import { LeadStatusBadge } from './lead-status-badge';
import { type LeadRow } from './types';

/** "2 shops · 3 tills" */
export function shopsAndTills(row: Pick<LeadRow, 'shopsCount' | 'tillsCount'>): string {
    return `${plural(row.shopsCount, 'shop')} · ${plural(row.tillsCount, 'till')}`;
}

export function AssignedTo({ admin }: { admin: LeadRow['assignedAdmin'] }) {
    if (!admin) {
        return <span className="text-muted-foreground">Unassigned</span>;
    }

    return (
        <span className="inline-flex min-w-0 items-center gap-2">
            <InitialsAvatar name={admin.name} size="sm" />
            <span className="truncate">{admin.name}</span>
        </span>
    );
}

/** Lead list columns. Column ids are the server sort keys. Fewer columns on narrow screens. */
export function leadColumns({ breakpoint }: { breakpoint: number }): ColumnDef<LeadRow>[] {
    const columns: ColumnDef<LeadRow>[] = [
        {
            id: 'business_name',
            header: 'Business',
            enableSorting: true,
            cell: ({ row }) => (
                <EntityCell
                    name={row.original.businessName}
                    shape="square"
                    subline={[row.original.contactName, row.original.town].filter(Boolean).join(' · ')}
                    className="max-w-72"
                />
            ),
        },
        {
            id: 'status',
            header: 'Status',
            enableSorting: true,
            cell: ({ row }) => <LeadStatusBadge status={row.original.status} />,
        },
        {
            id: 'source',
            header: 'Source',
            cell: ({ row }) => <span className="text-muted-foreground whitespace-nowrap">{leadSourceLabels[row.original.source]}</span>,
        },
        {
            id: 'tills_count',
            header: 'Shops and tills',
            enableSorting: true,
            meta: { label: 'Size' },
            cell: ({ row }) => <span className="whitespace-nowrap tabular-nums">{shopsAndTills(row.original)}</span>,
        },
        {
            id: 'assigned',
            header: 'Assigned',
            cell: ({ row }) => <AssignedTo admin={row.original.assignedAdmin} />,
        },
        {
            id: 'follow_up_at',
            header: 'Follow-up',
            enableSorting: true,
            cell: ({ row }) => <FollowUp at={row.original.followUpAt} empty="—" />,
        },
        {
            id: 'created_at',
            header: 'Received',
            enableSorting: true,
            cell: ({ row }) => <span className="text-muted-foreground whitespace-nowrap tabular-nums">{formatDate(row.original.createdAt)}</span>,
        },
    ];

    const hidden: Record<number, string[]> = {
        0: [],
        1: [],
        2: ['source', 'assigned', 'created_at'],
        3: ['source'],
        4: [],
    };

    return columns.filter((column) => !hidden[breakpoint]?.includes(column.id ?? ''));
}
