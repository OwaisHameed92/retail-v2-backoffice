import { RowActions } from '@/components/shared/row-actions';
import { StatusPill } from '@/components/shared/status-badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { formatMoney } from '@/lib/country';
import { type ColumnDef } from '@tanstack/react-table';
import { Eye, EyeOff, ImageIcon, Pencil } from 'lucide-react';
import { type OnlineProductRow } from './types';

/** Shown / hidden by you / not shown and why (the first reason, every reason in the tooltip). */
export function OnlineStatus({ row }: { row: OnlineProductRow }) {
    if (row.shown) {
        return <StatusPill tone="success">Shown</StatusPill>;
    }
    if (row.hidden && row.reasons.length === 0) {
        return <StatusPill tone="neutral">Hidden by you</StatusPill>;
    }
    const why = [row.hidden ? 'Hidden by you' : null, ...row.reasons].filter(Boolean).join(' · ');

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="inline-flex max-w-full cursor-help flex-col items-start gap-0.5" tabIndex={0}>
                    <StatusPill tone="warning">Not shown</StatusPill>
                    <span className="text-muted-foreground max-w-[14rem] truncate text-xs">{row.reasons[0] ?? 'Hidden by you'}</span>
                </span>
            </TooltipTrigger>
            <TooltipContent>{why}</TooltipContent>
        </Tooltip>
    );
}

interface ColumnOptions {
    canEdit: boolean;
    selected: string[];
    setSelected: (ids: string[]) => void;
    rows: OnlineProductRow[];
    onEdit: (row: OnlineProductRow) => void;
    onVisibility: (ids: string[], hidden: boolean) => void;
}

export function productColumns({ canEdit, selected, setSelected, rows, onEdit, onVisibility }: ColumnOptions): ColumnDef<OnlineProductRow>[] {
    const all = rows.length > 0 && rows.every((r) => selected.includes(r.id));
    const columns: ColumnDef<OnlineProductRow>[] = [];

    if (canEdit) {
        columns.push({
            id: 'select',
            header: () => (
                <Checkbox
                    checked={all ? true : selected.length > 0 ? 'indeterminate' : false}
                    aria-label="Select every product on this page"
                    onCheckedChange={(on) => setSelected(on === true ? rows.map((r) => r.id) : [])}
                />
            ),
            cell: ({ row }) => (
                <Checkbox
                    checked={selected.includes(row.original.id)}
                    aria-label={`Select ${row.original.name}`}
                    onClick={(e) => e.stopPropagation()}
                    onCheckedChange={(on) =>
                        setSelected(on === true ? [...selected, row.original.id] : selected.filter((id) => id !== row.original.id))
                    }
                />
            ),
            meta: { headerClassName: 'w-10', mobile: 'hidden' },
        });
    }

    columns.push(
        {
            accessorKey: 'name',
            header: 'Product',
            enableSorting: true,
            cell: ({ row }) => (
                <div className="flex min-w-0 items-center gap-3">
                    <div className="bg-muted flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border">
                        {row.original.photoUrl ? (
                            <img src={row.original.photoUrl} alt="" className="size-full object-cover" loading="lazy" />
                        ) : (
                            <ImageIcon className="text-muted-foreground size-4" aria-label="No photo" />
                        )}
                    </div>
                    <div className="min-w-0">
                        <p className="truncate font-medium">{row.original.name}</p>
                        <p className="text-muted-foreground truncate text-xs">
                            {[row.original.sku, row.original.description ? 'Online description' : null].filter(Boolean).join(' · ') || 'No code'}
                        </p>
                    </div>
                </div>
            ),
            meta: { mobile: 'title' },
        },
        {
            accessorKey: 'department',
            header: 'Department',
            cell: ({ row }) => row.original.department ?? <span className="text-muted-foreground">None</span>,
        },
        {
            accessorKey: 'price',
            header: 'Price',
            cell: ({ row }) =>
                row.original.price === null ? (
                    <span className="text-muted-foreground">No price</span>
                ) : (
                    <span className="tabular-nums">
                        {formatMoney(row.original.price)}
                        {row.original.isWeighed && <span className="text-muted-foreground"> /kg</span>}
                    </span>
                ),
            meta: { align: 'right' },
        },
        { id: 'status', header: 'Online', cell: ({ row }) => <OnlineStatus row={row.original} />, meta: { mobile: 'aside' } },
    );

    if (canEdit) {
        columns.push({
            id: 'actions',
            header: () => <span className="sr-only">Actions</span>,
            cell: ({ row }) => (
                <RowActions
                    label={`Actions for ${row.original.name}`}
                    actions={[
                        { label: 'Online details', icon: Pencil, onSelect: () => onEdit(row.original) },
                        row.original.hidden
                            ? { label: 'Show online', icon: Eye, onSelect: () => onVisibility([row.original.id], false) }
                            : { label: 'Hide online', icon: EyeOff, onSelect: () => onVisibility([row.original.id], true) },
                    ]}
                />
            ),
            meta: { align: 'right', mobile: 'actions' },
        });
    }

    return columns;
}
