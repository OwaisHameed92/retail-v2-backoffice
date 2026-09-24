import { EmailLogFilters } from '@/components/admin/emails/email-log-filters';
import { EmailLogSheet } from '@/components/admin/emails/email-log-sheet';
import { EmailTabs } from '@/components/admin/emails/email-tabs';
import { emailStatusTones, type EmailLogRow, type EmailSummary, type EmailLogFilters as Filters, type Option } from '@/components/admin/emails/types';
import { formatDateTime } from '@/components/admin/format';
import { DataTable, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { CircleAlert, Clock, Eye, MailCheck, Mails, SearchX } from 'lucide-react';
import { useState } from 'react';

interface EmailLogIndexProps {
    logs: Paginated<EmailLogRow>;
    filters: Filters;
    templateOptions: Option[];
    statusOptions: Option[];
    summary: EmailSummary;
}

const columns: ColumnDef<EmailLogRow>[] = [
    {
        id: 'to',
        header: 'To',
        enableSorting: true,
        cell: ({ row }) => (
            <div className="max-w-56 min-w-0">
                <div className="truncate font-medium">{row.original.to}</div>
                <div className="text-muted-foreground truncate text-xs md:hidden">{row.original.templateLabel}</div>
            </div>
        ),
    },
    {
        id: 'template',
        header: 'Template',
        enableSorting: true,
        cell: ({ row }) => (
            <span className="inline-flex items-center gap-2 whitespace-nowrap">
                {row.original.templateLabel}
                {row.original.isTest && (
                    <Badge variant="outline" className="font-normal">
                        Test
                    </Badge>
                )}
            </span>
        ),
    },
    {
        id: 'subject',
        header: 'Subject',
        cell: ({ row }) => <span className="text-muted-foreground line-clamp-1 max-w-80">{row.original.subject ?? '—'}</span>,
    },
    {
        id: 'company',
        header: 'Business',
        cell: ({ row }) =>
            row.original.company ? (
                <span className="whitespace-nowrap">{row.original.company.name}</span>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
    {
        id: 'status',
        header: 'Status',
        enableSorting: true,
        cell: ({ row }) => <StatusBadge status={row.original.status} tones={emailStatusTones} />,
    },
    {
        id: 'created_at',
        header: 'Sent',
        enableSorting: true,
        cell: ({ row }) => (
            <span className="text-muted-foreground whitespace-nowrap tabular-nums">
                {formatDateTime(row.original.sentAt ?? row.original.createdAt)}
            </span>
        ),
    },
];

export default function EmailLogIndex({ logs, filters, templateOptions, statusOptions, summary }: EmailLogIndexProps) {
    const [selected, setSelected] = useState<EmailLogRow | null>(null);
    const total = logs.meta.total;
    const filtered = Boolean(filters.template || filters.status || filters.from || filters.to || logs.meta.search);
    const nothingYet = total === 0 && !filtered;

    return (
        <AdminLayout>
            <Head title="Emails" />
            <PageHeader
                title="Emails"
                description={
                    <span className="tabular-nums">
                        Every email we send customers and staff. {total} {total === 1 ? 'email' : 'emails'}
                        {filtered ? ' match' : ''}.
                    </span>
                }
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('admin.emails.templates')}>
                            <Eye />
                            Preview templates
                        </Link>
                    </Button>
                }
            />
            <EmailTabs />

            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard label="Sent" value={<span className="tabular-nums">{summary.sent}</span>} hint="Last 30 days" icon={MailCheck} />
                <StatCard label="Failed" value={<span className="tabular-nums">{summary.failed}</span>} hint="Last 30 days" icon={CircleAlert} />
                <StatCard
                    label="Waiting to send"
                    value={<span className="tabular-nums">{summary.queued}</span>}
                    hint="In the queue now"
                    icon={Clock}
                />
            </div>

            <DataTable
                columns={columns}
                data={logs.data}
                meta={logs.meta}
                only={['logs', 'filters', 'templateOptions', 'summary']}
                searchPlaceholder="Search email address or subject"
                filters={<EmailLogFilters filters={filters} templateOptions={templateOptions} statusOptions={statusOptions} />}
                getRowId={(row) => row.id}
                onRowClick={setSelected}
                empty={
                    nothingYet ? (
                        <EmptyState
                            icon={Mails}
                            title="No emails sent yet"
                            body="Welcome emails, trial reminders and account notices appear here as soon as they are sent. Preview the templates or send yourself a test."
                            action={
                                <Button asChild>
                                    <Link href={route('admin.emails.templates')}>Preview templates</Link>
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState icon={SearchX} title="No emails match" body="Try a different search, template, status or date range." />
                    )
                }
            />

            <EmailLogSheet
                log={selected}
                templateKeys={templateOptions.map((option) => option.value)}
                onOpenChange={(open) => !open && setSelected(null)}
            />
        </AdminLayout>
    );
}
