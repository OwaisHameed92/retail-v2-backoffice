import { transferColumns } from '@/components/app/transfers/columns';
import { filterQuery, money, TransferFilterBar, TransferTabs } from '@/components/app/transfers/format';
import { type TransferIndexProps } from '@/components/app/transfers/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeftRight, Download, Info, PackageCheck, Truck, type LucideIcon } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['rows', 'filters', 'stats'];
const count = new Intl.NumberFormat('en-GB');
const ICONS: LucideIcon[] = [Truck, ArrowLeftRight, PackageCheck, AlertTriangle];

/** Stock transfers between shops (module 5.3), read only: the shops raise, dispatch and receive them on the till. */
export default function TransferIndex(props: TransferIndexProps) {
    const { rows, filters, stats, shops, oneShop } = props;
    const { update } = useTableQuery({ only: ONLY });
    const columns = useMemo(() => transferColumns(), []);
    const filtered = Boolean(rows.meta.search) || Boolean((!oneShop && filters.shop) || filters.flow || filters.status || filters.from || filters.to);
    const query = filterQuery(filters, { search: rows.meta.search });

    return (
        <AppLayout>
            <Head title="Stock transfers" />

            <PageHeader
                title="Stock transfers"
                description="Stock moved between your shops: what was sent, what arrived, and whether the receiving shop's till has it yet."
                actions={
                    <Button variant="outline" asChild>
                        <a href={route('app.transfers.export', query)}>
                            <Download />
                            Export CSV
                        </a>
                    </Button>
                }
                tabs={<TransferTabs current="list" query={filterQuery({ ...filters, status: null })} />}
            />

            <Alert variant="info">
                <Info />
                <AlertDescription>
                    {oneShop ? `You are seeing transfers from or to ${shops[0]?.name ?? 'your shop'} only. ` : ''}
                    Transfers are raised, dispatched and received on the tills. A dispatched transfer is sent to the receiving shop&apos;s till at its
                    next sync, and the receipt comes back to the sending shop the same way.
                </AlertDescription>
            </Alert>

            <StatGrid columns={4}>
                {stats.map((stat, i) => (
                    <StatCard
                        key={stat.label}
                        label={stat.label}
                        value={stat.format === 'money' ? money(stat.value) : count.format(Number(stat.value))}
                        hint={stat.hint}
                        tone={stat.tone}
                        icon={ICONS[i]}
                    />
                ))}
            </StatGrid>

            <DataTable
                columns={columns}
                data={rows.data}
                meta={rows.meta}
                only={ONLY}
                searchPlaceholder="Search by reference or note"
                filters={<TransferFilterBar filters={filters} shared={props} update={update} />}
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.transfers.show', row.id))}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={ArrowLeftRight}
                            title="No stock transfers yet"
                            body="When one shop sends stock to another on the till, the transfer appears here after the till syncs."
                        />
                    )
                }
            />
        </AppLayout>
    );
}
