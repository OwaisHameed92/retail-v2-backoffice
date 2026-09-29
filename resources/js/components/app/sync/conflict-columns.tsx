import { EntityCell } from '@/components/shared/entity-cell';
import { relativeTime } from '@/lib/relative-time';
import { type ColumnDef } from '@tanstack/react-table';
import { GitCompareArrows, Store } from 'lucide-react';
import { ClashBadge, ConflictStatusBadge, formatDateTimeShort, KindPill } from './format';
import { type ClashRow, type ConflictRow } from './types';

function When({ iso }: { iso: string | null }) {
    if (!iso) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <time dateTime={iso} title={formatDateTimeShort(iso)} className="text-muted-foreground whitespace-nowrap tabular-nums">
            {relativeTime(iso)}
        </time>
    );
}

function Shop({ name }: { name: string | null }) {
    return name ? (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
            <Store className="text-muted-foreground size-3.5" aria-hidden />
            {name}
        </span>
    ) : (
        <span className="text-muted-foreground">—</span>
    );
}

/** Portal review list. Column ids are the server sort keys; phones get cards (what changed, why, shop, received). */
export function conflictColumns(): ColumnDef<ConflictRow>[] {
    return [
        {
            id: 'entity',
            header: 'What changed',
            enableSorting: true,
            cell: ({ row }) => (
                <EntityCell
                    name={row.original.subject ?? row.original.entityLabel}
                    subline={row.original.subject ? `${row.original.entityLabel} · ${row.original.entityId}` : row.original.entityId}
                    icon={GitCompareArrows}
                    className="max-w-80"
                />
            ),
        },
        { id: 'kind', header: 'Why', enableSorting: true, cell: ({ row }) => <KindPill kind={row.original.kind} label={row.original.kindLabel} /> },
        { id: 'shop', header: 'Shop', cell: ({ row }) => <Shop name={row.original.branch} /> },
        { id: 'status', header: 'Status', cell: ({ row }) => <ConflictStatusBadge status={row.original.status} /> },
        { id: 'created_at', header: 'Received', enableSorting: true, cell: ({ row }) => <When iso={row.original.receivedAt} /> },
    ];
}

/** Shop clash list (read only). */
export function clashColumns(): ColumnDef<ClashRow>[] {
    return [
        {
            id: 'entity',
            header: 'What clashed',
            enableSorting: true,
            cell: ({ row }) => (
                <EntityCell
                    name={row.original.subject ?? row.original.entityLabel}
                    subline={
                        row.original.subject
                            ? `${row.original.entityLabel} · ${row.original.detail || row.original.entityId}`
                            : row.original.detail || row.original.entityId
                    }
                    icon={GitCompareArrows}
                    className="max-w-96"
                />
            ),
        },
        { id: 'shop', header: 'Shop', cell: ({ row }) => <Shop name={row.original.branch} /> },
        {
            id: 'status',
            header: 'At the shop',
            cell: ({ row }) => <ClashBadge resolution={row.original.resolution} label={row.original.resolutionLabel} />,
        },
        { id: 'detected_at', header: 'Detected', enableSorting: true, cell: ({ row }) => <When iso={row.original.detectedAt} /> },
    ];
}
