import { DataTable, useTableQuery, type TableParams } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type ColumnDef } from '@tanstack/react-table';
import { Bot, ChevronLeft, ChevronRight, ScrollText, ShieldCheck, X } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { AuditDrawer } from './audit-drawer';
import { AuditFilters, resetCursor } from './audit-filters';
import { formatAuditTime, type AuditEntry, type AuditLogProps } from './types';

const ONLY = ['entries', 'filters', 'options'];

interface AuditLogViewProps extends AuditLogProps {
    tenantView: boolean;
    /** Admin: the business picker shown first among the filters. */
    beforeFilters?: ReactNode;
}

function columns(tenantView: boolean): ColumnDef<AuditEntry>[] {
    return [
        {
            id: 'at',
            header: 'When',
            cell: ({ row }) => <span className="text-muted-foreground whitespace-nowrap tabular-nums">{formatAuditTime(row.original.at)}</span>,
            meta: { mobile: 'aside' },
        },
        {
            id: 'actor',
            header: 'Who',
            cell: ({ row }) => {
                const actor = row.original.actor;
                const icon = actor.type === 'system' ? Bot : actor.type === 'staff' ? ShieldCheck : undefined;

                return <EntityCell name={actor.name} subline={actor.detail ?? undefined} icon={icon} />;
            },
            meta: { mobile: 'title' },
        },
        {
            id: 'action',
            header: 'Action',
            cell: ({ row }) => (
                <div className="grid min-w-0">
                    <span className="font-medium">{row.original.actionLabel}</span>
                    <span className="text-muted-foreground truncate font-mono text-xs">{row.original.action}</span>
                </div>
            ),
        },
        {
            id: 'subject',
            header: 'Record',
            cell: ({ row }) =>
                row.original.subject ? (
                    <div className="grid min-w-0">
                        <span>{row.original.subject.label}</span>
                        {row.original.subject.id && (
                            <span className="text-muted-foreground max-w-48 truncate font-mono text-xs">{row.original.subject.id}</span>
                        )}
                    </div>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        ...(!tenantView
            ? [
                  {
                      id: 'company',
                      header: 'Business',
                      cell: ({ row }) => row.original.company?.name ?? <span className="text-muted-foreground">—</span>,
                  } satisfies ColumnDef<AuditEntry>,
              ]
            : []),
        {
            id: 'changes',
            header: 'Changes',
            cell: ({ row }) => {
                const n = row.original.changes.length;

                return n === 0 ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <span className="tabular-nums">
                        {n} {n === 1 ? 'field' : 'fields'}
                    </span>
                );
            },
            meta: { align: 'right' },
        },
    ];
}

/** The audit log list shared by the admin and tenant screens: filters, search, keyset paging and the detail drawer. */
export function AuditLogView({ entries, filters, options, tenantView, beforeFilters }: AuditLogViewProps) {
    const { update, loading } = useTableQuery({ only: ONLY });
    const [open, setOpen] = useState<AuditEntry | null>(null);
    const cols = useMemo(() => columns(tenantView), [tenantView]);
    const hasFilters = Object.entries(filters).some(([key, value]) => value !== null && (!tenantView || key !== 'company'));

    const change = (params: TableParams) => {
        const { page, ...rest } = params;
        void page;
        update({ ...rest, ...resetCursor });
    };

    const pager = (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-muted-foreground text-sm">Newest first · times are UK time</p>
            <div className="flex items-center gap-2">
                <Select value={String(entries.perPage)} onValueChange={(perPage) => update({ perPage: Number(perPage), ...resetCursor })}>
                    <SelectTrigger className="h-8 w-28" aria-label="Rows per page">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {[25, 50, 100].map((n) => (
                            <SelectItem key={n} value={String(n)}>
                                {n} per page
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!entries.newer || loading}
                    onClick={() => update({ before: entries.newer ?? undefined, after: undefined })}
                >
                    <ChevronLeft />
                    Newer
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!entries.older || loading}
                    onClick={() => update({ after: entries.older ?? undefined, before: undefined })}
                >
                    Older
                    <ChevronRight />
                </Button>
            </div>
        </div>
    );

    return (
        <>
            {filters.subjectId && (
                <div className="bg-muted/50 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-2.5 text-sm">
                    <span>
                        Showing entries for one record: <span className="font-mono text-[13px]">{filters.subjectId}</span>
                    </span>
                    <Button variant="ghost" size="sm" onClick={() => update({ subjectId: undefined, subjectType: undefined, ...resetCursor })}>
                        <X />
                        Every record
                    </Button>
                </div>
            )}

            <DataTable
                columns={cols}
                data={entries.data}
                meta={{ page: 1, perPage: entries.perPage, total: entries.data.length, search: filters.search }}
                onChange={change}
                searchPlaceholder="Search action, record id or IP"
                filters={<AuditFilters filters={filters} options={options} update={update} tenantView={tenantView} before={beforeFilters} />}
                toolbarActions={
                    hasFilters ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                update({
                                    actor: undefined,
                                    company: undefined,
                                    action: undefined,
                                    subjectType: undefined,
                                    subjectId: undefined,
                                    from: undefined,
                                    to: undefined,
                                    search: undefined,
                                    ...resetCursor,
                                })
                            }
                        >
                            <X />
                            Clear filters
                        </Button>
                    ) : undefined
                }
                getRowId={(row) => row.id}
                onRowClick={setOpen}
                loading={loading}
                footer={pager}
                empty={
                    <EmptyState
                        icon={ScrollText}
                        title={hasFilters ? 'Nothing matches these filters' : 'No activity yet'}
                        body={
                            hasFilters
                                ? 'Try other filters or a wider date range.'
                                : 'Changes made in the backoffice, sign-in security events and automatic jobs are recorded here.'
                        }
                    />
                }
            />

            <AuditDrawer
                entry={open}
                onClose={() => setOpen(null)}
                tenantView={tenantView}
                onFilter={(params) => {
                    setOpen(null);
                    update({ ...params, ...resetCursor });
                }}
            />
        </>
    );
}

/** The current filters as query params, for the CSV link. */
export function exportHref(base: string, filters: AuditLogProps['filters']): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value) {
            params.set(key, value);
        }
    }
    const query = params.toString();

    return query ? `${base}?${query}` : base;
}
