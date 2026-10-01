import { AuditLogView, exportHref } from '@/components/shared/audit/audit-log-view';
import { type AuditLogProps } from '@/components/shared/audit/types';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';

/** The business's own activity log: who changed what in the backoffice, and sign-in security events. */
export default function Activity(props: AuditLogProps) {
    return (
        <AppLayout>
            <Head title="Activity" />

            <PageHeader
                title="Activity"
                description="Who changed what in your backoffice, including changes made by Switch & Save for you. Entries cannot be edited or deleted."
                actions={
                    <Button variant="outline" asChild>
                        <a href={exportHref(route('app.activity.export'), props.filters)}>
                            <Download />
                            Export CSV
                        </a>
                    </Button>
                }
            />

            <AuditLogView {...props} tenantView />
        </AppLayout>
    );
}
