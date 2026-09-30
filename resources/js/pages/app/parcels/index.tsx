import { CashFilters } from '@/components/app/cash/cash-page';
import { FilterSelect } from '@/components/app/setup/fields';
import { londonDateTime } from '@/components/app/pharmacy/format';
import { type ParcelDirection, type ParcelRow, type ParcelsProps, type ParcelStatus } from '@/components/app/pharmacy/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { number } from '@/components/shared/trading/format';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Clock, Info, PackageCheck, PackageOpen, PackagePlus } from 'lucide-react';

const ONLY = ['parcels', 'summary', 'carriers', 'carrierOptions', 'only', 'filters', 'options'];
const DIRECTIONS: Record<ParcelDirection, string> = { dropOff: 'Drop-off', collection: 'Collection' };
const STATUSES: Record<ParcelStatus, string> = { open: 'In the shop', handedOver: 'Handed over' };

const columns: ColumnDef<ParcelRow>[] = [
    {
        id: 'tracking',
        header: 'Parcel',
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-mono text-sm font-medium">{row.original.trackingCode}</span>
                <span className="text-muted-foreground text-xs">
                    {[row.original.carrier ?? 'Unknown carrier', row.original.customer, row.original.shop].filter(Boolean).join(' · ')}
                </span>
            </div>
        ),
    },
    {
        id: 'direction',
        header: 'Type',
        meta: { mobile: 'field' },
        cell: ({ row }) => (row.original.direction ? DIRECTIONS[row.original.direction] : '—'),
    },
    {
        id: 'registered',
        header: 'Booked in',
        meta: { mobile: 'field' },
        cell: ({ row }) => <span className="text-sm">{londonDateTime(row.original.registeredAt)}</span>,
    },
    {
        id: 'status',
        header: 'Status',
        meta: { mobile: 'aside' },
        cell: ({ row }) => {
            const p = row.original;
            if (p.status === 'handedOver') {
                return (
                    <div className="grid justify-items-start gap-1">
                        <StatusPill tone="success">Handed over</StatusPill>
                        <span className="text-muted-foreground text-xs">
                            {londonDateTime(p.handedOverAt)}
                            {p.idCheck ? ` · ID: ${p.idCheck}` : ''}
                        </span>
                    </div>
                );
            }
            const long = (p.waitingDays ?? 0) >= 7;

            return (
                <div className="grid justify-items-start gap-1">
                    <StatusPill tone={long ? 'warning' : 'info'}>In the shop</StatusPill>
                    <span className="text-muted-foreground text-xs">
                        {p.waitingDays === 0 ? 'Since today' : `${p.waitingDays} ${p.waitingDays === 1 ? 'day' : 'days'}`}
                    </span>
                </div>
            );
        },
    },
];

/** Module 5.10: parcel drop-offs and collections booked on the tills (read only), and each shop's carriers. */
export default function ParcelsIndex({ parcels, summary, carriers, carrierOptions, only, filters, options }: ParcelsProps) {
    const { update, loading } = useTableQuery({ only: ONLY });
    const showShop = !filters.shopLocked && filters.shop === null && options.shops.length > 1;

    return (
        <AppLayout>
            <Head title="Parcels" />
            <PageHeader
                title="Parcels"
                description="Parcels customers dropped off or collected at your shops, booked in and handed over on the tills."
            />
            <div className="grid gap-6">
                <StatGrid>
                    <StatCard label="Booked in" value={number(summary.registered)} hint="In these dates" icon={PackagePlus} tone="primary" />
                    <StatCard
                        label="Drop-offs / collections"
                        value={`${number(summary.dropOffs)} / ${number(summary.collections)}`}
                        hint="In these dates"
                        icon={PackageOpen}
                        tone="neutral"
                    />
                    <StatCard label="Handed over" value={number(summary.handedOver)} hint="To the carrier or the customer" icon={PackageCheck} tone="success" />
                    <StatCard
                        label="In the shop now"
                        value={number(summary.waiting)}
                        hint={summary.waitingLong ? `${number(summary.waitingLong)} waiting over ${summary.waitingDays} days` : 'Any date'}
                        icon={Clock}
                        tone={summary.waitingLong ? 'warning' : 'neutral'}
                    />
                </StatGrid>

                <SectionCard
                    title="Carriers"
                    description="Each shop's carriers, as set on its till. Counts are for these dates; waiting is now."
                    flush
                >
                    {carriers.length === 0 ? (
                        <EmptyState size="sm" title="No carriers" body="Carriers are added on the till (Parcels, Carriers) and appear here after it syncs." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Carrier</TableHead>
                                    {showShop && <TableHead className="hidden md:table-cell">Shop</TableHead>}
                                    <TableHead className="text-right">Drop-offs</TableHead>
                                    <TableHead className="text-right">Collections</TableHead>
                                    <TableHead className="hidden text-right sm:table-cell">Handed over</TableHead>
                                    <TableHead className="text-right">Waiting</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {carriers.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell>
                                            <span className="font-medium">{c.name}</span>
                                            {!c.isActive && <span className="text-muted-foreground text-xs"> · turned off</span>}
                                        </TableCell>
                                        {showShop && <TableCell className="hidden md:table-cell">{c.shop ?? 'Unknown shop'}</TableCell>}
                                        <TableCell className="text-right tabular-nums">{number(c.dropOffs)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{number(c.collections)}</TableCell>
                                        <TableCell className="hidden text-right tabular-nums sm:table-cell">{number(c.handedOver)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{number(c.waiting)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SectionCard>

                {summary.waitingLong > 0 && (
                    <Alert variant="warning">
                        <Info />
                        <AlertDescription>
                            {summary.waitingLong === 1 ? 'One parcel has' : `${summary.waitingLong} parcels have`} been in the shop over {summary.waitingDays}{' '}
                            days. Most carriers take uncollected parcels back after a week or two.
                        </AlertDescription>
                    </Alert>
                )}

                <DataTable
                    columns={columns}
                    data={parcels.data}
                    meta={parcels.meta}
                    onChange={update}
                    loading={loading}
                    searchPlaceholder="Search tracking code or customer"
                    filters={
                        <CashFilters filters={filters} options={options} update={update} showTill={false}>
                            {carrierOptions.length > 1 && (
                                <FilterSelect
                                    value={only.carrier}
                                    onChange={(carrier) => update({ carrier, page: undefined })}
                                    all="Every carrier"
                                    options={carrierOptions}
                                    label="Filter by carrier"
                                />
                            )}
                            <FilterSelect
                                value={only.direction}
                                onChange={(type) => update({ type, page: undefined })}
                                all="Drop-offs and collections"
                                options={(Object.keys(DIRECTIONS) as ParcelDirection[]).map((d) => ({ value: d, label: DIRECTIONS[d] }))}
                                label="Filter by type"
                            />
                            <FilterSelect
                                value={only.status}
                                onChange={(status) => update({ status, page: undefined })}
                                all="Any status"
                                options={(Object.keys(STATUSES) as ParcelStatus[]).map((s) => ({ value: s, label: STATUSES[s] }))}
                                label="Filter by status"
                            />
                        </CashFilters>
                    }
                    getRowId={(row) => row.id}
                    empty={
                        <EmptyState
                            icon={PackageOpen}
                            title="No parcels"
                            body="Parcels booked in on the tills in these dates appear here after they sync."
                        />
                    }
                />
            </div>
        </AppLayout>
    );
}
