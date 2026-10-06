import { licenceColumns } from '@/components/admin/licences/licence-columns';
import { LicenceFilters } from '@/components/admin/licences/licence-filters';
import { type LicenceIndexProps } from '@/components/admin/licences/types';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { useBreakpoint } from '@/hooks/use-min-width';
import AdminLayout from '@/layouts/admin-layout';
import { formatNumber } from '@/lib/country';
import { Head, Link, router } from '@inertiajs/react';
import { Building2, KeyRound } from 'lucide-react';
import { useMemo } from 'react';

export default function LicenceIndex({ licences, filters, statuses, counts, total, plans }: LicenceIndexProps) {
    const breakpoint = useBreakpoint();
    const columns = useMemo(() => licenceColumns({ breakpoint }), [breakpoint]);
    const filtered = Boolean(licences.meta.search || filters.status || filters.plan || filters.company);
    const trading = (counts.trial ?? 0) + (counts.active ?? 0) + (counts.grace ?? 0);

    return (
        <AdminLayout>
            <Head title="Licences" />

            <PageHeader
                title="Licences"
                description={
                    total === 0
                        ? 'One licence per till. Licences are issued when tills are added to a tenant.'
                        : `${formatNumber(total)} ${total === 1 ? 'licence' : 'licences'}, one per till. ${formatNumber(trading)} can trade right now.`
                }
            />

            <DataTable
                columns={columns}
                data={licences.data}
                meta={licences.meta}
                only={['licences', 'filters', 'counts']}
                searchPlaceholder="Search key ending, PC or business"
                filters={<LicenceFilters filters={filters} statuses={statuses} counts={counts} plans={plans} />}
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('admin.licences.show', row.id))}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={KeyRound}
                            title="No licences yet"
                            body="Every till gets a licence when it is added to a tenant. Add a tenant to issue the first keys."
                            action={
                                <Button asChild>
                                    <Link href={route('admin.tenants.index')}>
                                        <Building2 />
                                        Go to tenants
                                    </Link>
                                </Button>
                            }
                        />
                    )
                }
            />
        </AdminLayout>
    );
}
