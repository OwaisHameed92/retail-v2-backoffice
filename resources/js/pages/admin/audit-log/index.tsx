import { BusinessPicker } from '@/components/admin/licences/licence-filters';
import { resetCursor } from '@/components/shared/audit/audit-filters';
import { AuditLogView, exportHref } from '@/components/shared/audit/audit-log-view';
import { type AuditLogProps } from '@/components/shared/audit/types';
import { useTableQuery } from '@/components/shared/data-table';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head } from '@inertiajs/react';
import { Building2, Download, X } from 'lucide-react';
import { useState } from 'react';

/** Every audit entry across every business (owner and support). Read only. */
export default function AdminAuditLog(props: AuditLogProps) {
    const [picking, setPicking] = useState(false);
    const { update } = useTableQuery({ only: ['entries', 'filters', 'options'] });
    const company = props.options.company;

    const businessFilter = company ? (
        <Button variant="outline" className="h-9 max-w-full justify-between sm:w-56" onClick={() => update({ company: undefined, ...resetCursor })}>
            <span className="flex min-w-0 items-center gap-2">
                <Building2 className="text-muted-foreground size-4 shrink-0" />
                <span className="truncate">{company.name}</span>
            </span>
            <X className="text-muted-foreground size-4" aria-label="Every business" />
        </Button>
    ) : (
        <Button variant="outline" className="text-muted-foreground h-9 justify-start font-normal sm:w-44" onClick={() => setPicking(true)}>
            <Building2 className="size-4" />
            Every business
        </Button>
    );

    return (
        <AdminLayout width="wide">
            <Head title="Audit log" />

            <PageHeader
                title="Audit log"
                description="Who changed what, across the admin console and every business. Entries cannot be edited or deleted."
                actions={
                    <Button variant="outline" asChild>
                        <a href={exportHref(route('admin.audit-log.export'), props.filters)}>
                            <Download />
                            Export CSV
                        </a>
                    </Button>
                }
            />

            <AuditLogView {...props} tenantView={false} beforeFilters={businessFilter} />

            <BusinessPicker
                open={picking}
                onOpenChange={setPicking}
                title="Filter by business"
                description="Show only the audit entries of one customer."
                onPick={(id) => {
                    setPicking(false);
                    update({ company: id, ...resetCursor });
                }}
            />
        </AdminLayout>
    );
}
