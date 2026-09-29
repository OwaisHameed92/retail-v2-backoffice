import { LocalKeyBadges, MoveStatusBadge, UploadProgress } from '@/components/admin/cloud-link/cloud-link-badges';
import { type CloudMove, type LocalKeyRecord, reportedViaLabels } from '@/components/admin/cloud-link/types';
import { formatDateTime } from '@/components/admin/format';
import { formatDate } from '@/components/admin/tenants/format';
import { EntityCell } from '@/components/shared/entity-cell';
import { Button } from '@/components/ui/button';
import { relativeTime } from '@/lib/relative-time';
import { type ColumnDef } from '@tanstack/react-table';
import { CloudUpload, KeyRound, Trash2 } from 'lucide-react';

function When({ at, empty = '—' }: { at: string | null; empty?: string }) {
    return at ? (
        <span className="whitespace-nowrap tabular-nums" title={formatDateTime(at)}>
            {relativeTime(at)}
        </span>
    ) : (
        <span className="text-muted-foreground">{empty}</span>
    );
}

/** Columns of the "Moves to the cloud" list (module 2.8). Sortable ids are the server's sort keys. */
export function moveColumns(): ColumnDef<CloudMove>[] {
    return [
        {
            id: 'company_name',
            header: 'Shop',
            enableSorting: true,
            meta: { mobile: 'title' },
            cell: ({ row: { original: m } }) => (
                <EntityCell
                    name={m.branch.name ?? 'Unknown shop'}
                    subline={`${m.company.name ?? '—'}${m.branch.code ? ` · ${m.branch.code}` : ''}`}
                    href={route('admin.tenants.show', m.company.id)}
                    icon={CloudUpload}
                    shape="square"
                />
            ),
        },
        { id: 'status', header: 'Status', meta: { mobile: 'aside' }, cell: ({ row: { original: m } }) => <MoveStatusBadge move={m} /> },
        { id: 'progress', header: 'History', cell: ({ row: { original: m } }) => <UploadProgress move={m} /> },
        {
            id: 'device',
            header: 'Main till',
            cell: ({ row: { original: m } }) => (
                <div className="leading-tight">
                    <div>{m.deviceName ?? '—'}</div>
                    <div className="text-muted-foreground font-mono text-xs">{m.installCode ?? ''}</div>
                </div>
            ),
        },
        {
            id: 'licence',
            header: 'Licence',
            cell: ({ row: { original: m } }) => (
                <div className="text-sm leading-tight">
                    <div>{m.carriedOverDays > 0 ? `${m.carriedOverDays} days carried over` : 'Nothing carried over'}</div>
                    <div className="text-muted-foreground text-xs">
                        Ids: business {m.companyIdAction ?? '—'}, shop {m.branchIdAction ?? '—'}
                    </div>
                </div>
            ),
        },
        { id: 'started_at', header: 'Started', enableSorting: true, cell: ({ row: { original: m } }) => <When at={m.startedAt} /> },
        { id: 'last_batch_at', header: 'Last batch', enableSorting: true, cell: ({ row: { original: m } }) => <When at={m.lastBatchAt} empty="None yet" /> },
        { id: 'completed_at', header: 'Completed', enableSorting: true, cell: ({ row: { original: m } }) => <When at={m.completedAt} empty="Not yet" /> },
    ];
}

/** Columns of the local key register (module 2.8). */
export function localKeyColumns(onClear?: (record: LocalKeyRecord) => void): ColumnDef<LocalKeyRecord>[] {
    return [
        {
            id: 'key',
            header: 'Key',
            meta: { mobile: 'title' },
            cell: ({ row: { original: k } }) => (
                <EntityCell
                    name={k.businessName ?? 'No shop name'}
                    subline={`${k.branchName ?? '—'} · ${k.licenceId}`}
                    href={k.company ? route('admin.tenants.show', k.company.id) : undefined}
                    icon={KeyRound}
                    shape="square"
                    monoSubline
                />
            ),
        },
        { id: 'state', header: 'State', meta: { mobile: 'aside' }, cell: ({ row: { original: k } }) => <LocalKeyBadges record={k} /> },
        {
            id: 'install',
            header: 'Bound to',
            cell: ({ row: { original: k } }) => (
                <div className="leading-tight">
                    <div className="font-mono">{k.installCode}</div>
                    <div className="text-muted-foreground text-xs">
                        {k.deviceName ?? 'Unknown PC'}
                        {k.installIdEnding ? ` · …${k.installIdEnding}` : ''}
                    </div>
                </div>
            ),
        },
        {
            id: 'customer',
            header: 'Customer',
            cell: ({ row: { original: k } }) =>
                k.company ? <span>{k.company.name}</span> : <span className="text-muted-foreground">Not a customer yet</span>,
        },
        {
            id: 'expires_at',
            header: 'Ends',
            enableSorting: true,
            cell: ({ row: { original: k } }) => <span className="whitespace-nowrap tabular-nums">{formatDate(k.expiresAt)}</span>,
        },
        {
            id: 'last_reported_at',
            header: 'Last reported',
            enableSorting: true,
            cell: ({ row: { original: k } }) => (
                <div className="leading-tight">
                    <When at={k.lastReportedAt} />
                    <div className="text-muted-foreground text-xs">{reportedViaLabels[k.reportedVia]}</div>
                </div>
            ),
        },
        ...(onClear
            ? [
                  {
                      id: 'actions',
                      header: () => <span className="sr-only">Actions</span>,
                      cell: ({ row: { original: k } }) => (
                          <Button
                              variant="ghost"
                              size="sm"
                              onClick={(event) => {
                                  event.stopPropagation();
                                  onClear(k);
                              }}
                          >
                              <Trash2 />
                              Clear
                          </Button>
                      ),
                  } satisfies ColumnDef<LocalKeyRecord>,
              ]
            : []),
    ];
}
