import { formatDateTimeShort } from '@/components/admin/tenants/format';
import { type TenantActivityRow } from '@/components/admin/tenants/types';
import { DataTable, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { Card } from '@/components/ui/card';
import { type ColumnDef } from '@tanstack/react-table';
import { History } from 'lucide-react';

const columns: ColumnDef<TenantActivityRow>[] = [
    {
        id: 'description',
        header: 'What happened',
        cell: ({ row }) => (
            <div className="min-w-0">
                <p className="text-sm break-words">{row.original.description}</p>
                <p className="text-muted-foreground text-xs sm:hidden">
                    {row.original.actorName} · {formatDateTimeShort(row.original.createdAt)}
                </p>
            </div>
        ),
    },
    {
        id: 'actor',
        header: () => <span className="hidden sm:inline">By</span>,
        cell: ({ row }) => <span className="text-muted-foreground hidden sm:inline">{row.original.actorName}</span>,
    },
    {
        id: 'created_at',
        header: () => <span className="hidden sm:inline">When</span>,
        cell: ({ row }) => (
            <span className="text-muted-foreground hidden whitespace-nowrap tabular-nums sm:inline">
                {formatDateTimeShort(row.original.createdAt)}
            </span>
        ),
    },
];

/** Audit log entries for this company, newest first. */
export function ActivityPanel({ activity }: { activity: Paginated<TenantActivityRow> }) {
    return (
        <Card className="p-4 sm:p-5">
            <div className="mb-4">
                <h2 className="text-base font-semibold">Activity</h2>
                <p className="text-muted-foreground text-sm">Everything done to this account, by our staff and by the customer.</p>
            </div>
            <DataTable
                columns={columns}
                data={activity.data}
                meta={activity.meta}
                only={['activity']}
                searchable={false}
                getRowId={(row) => row.id}
                empty={<EmptyState icon={History} title="No activity yet" body="Changes to this account are recorded here." />}
            />
        </Card>
    );
}
