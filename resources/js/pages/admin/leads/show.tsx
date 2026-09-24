import { DuplicatesAlert } from '@/components/admin/leads/duplicates-alert';
import { FollowUp } from '@/components/admin/leads/follow-up';
import { businessTypeLabels, formatDate, formatDateTimeShort, leadSourceLabels, telHref } from '@/components/admin/leads/format';
import { LeadActions } from '@/components/admin/leads/lead-actions';
import { AssignedTo } from '@/components/admin/leads/lead-columns';
import { LeadStatusBadge } from '@/components/admin/leads/lead-status-badge';
import { LeadTimeline } from '@/components/admin/leads/lead-timeline';
import { type LeadShowProps } from '@/components/admin/leads/types';
import { type AdminSharedData } from '@/components/admin/types';
import { DescriptionList } from '@/components/shared/description-list';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Archive, ArrowRight, BadgeCheck, CalendarClock, CircleX, Store, UserRound } from 'lucide-react';

function StatusAlerts({ lead, canViewTenant }: { lead: LeadShowProps['lead']; canViewTenant: boolean }) {
    if (lead.archived) {
        return (
            <Alert variant="warning">
                <Archive className="size-4" />
                <AlertTitle>Archived</AlertTitle>
                <AlertDescription>This lead is hidden from the lists. Restore it to work it again.</AlertDescription>
            </Alert>
        );
    }

    if (lead.status === 'converted') {
        return (
            <Alert variant="success">
                <BadgeCheck className="size-4" />
                <AlertTitle>Trial approved {lead.convertedAt && `on ${formatDate(lead.convertedAt)}`}</AlertTitle>
                <AlertDescription>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            {lead.company ? (
                                <>
                                    {lead.company.name} is a tenant{lead.company.deleted ? ' (deleted)' : ''}
                                    {lead.convertedBy && `, set up by ${lead.convertedBy.name}`}.
                                </>
                            ) : (
                                'The tenant made from this lead no longer exists.'
                            )}
                        </span>
                        {lead.company && canViewTenant && !lead.company.deleted && (
                            <Button asChild size="sm" variant="outline" className="bg-card shrink-0">
                                <Link href={route('admin.tenants.show', lead.company.id)}>
                                    Open tenant
                                    <ArrowRight />
                                </Link>
                            </Button>
                        )}
                    </div>
                </AlertDescription>
            </Alert>
        );
    }

    if (lead.status === 'rejected') {
        return (
            <Alert>
                <CircleX className="size-4" />
                <AlertTitle>Rejected {lead.rejectedAt && `on ${formatDate(lead.rejectedAt)}`}</AlertTitle>
                <AlertDescription>{lead.rejectionReason ?? 'No reason given.'}</AlertDescription>
            </Alert>
        );
    }

    return null;
}

export default function LeadShow({ lead, notes, duplicates, approval, options, defaultFollowUpTime, can }: LeadShowProps) {
    const { admin } = usePage<AdminSharedData>().props;
    const utm = lead.utm ? Object.entries(lead.utm).map(([key, value]) => `${key.replace(/^utm_/, '')}: ${value}`) : [];

    return (
        <AdminLayout>
            <Head title={lead.businessName} />

            <PageHeader
                title={lead.businessName}
                status={<LeadStatusBadge status={lead.status} withHelp />}
                back={{ href: route('admin.leads.index'), label: 'Leads' }}
                media={<InitialsAvatar name={lead.businessName} shape="square" size="lg" icon={Store} />}
                description={[lead.contactName, lead.town, `${leadSourceLabels[lead.source]} · received ${formatDate(lead.createdAt)}`]
                    .filter(Boolean)
                    .join(' · ')}
                meta={
                    <>
                        <span className="inline-flex items-center gap-1.5">
                            <UserRound className="size-3.5" aria-hidden />
                            <AssignedTo admin={lead.assignedAdmin} />
                        </span>
                        {(lead.status === 'new' || lead.status === 'contacted') && (
                            <span className="inline-flex items-center gap-1.5">
                                {!lead.followUpAt && <CalendarClock className="size-3.5" aria-hidden />}
                                <FollowUp at={lead.followUpAt} empty="No follow-up" />
                            </span>
                        )}
                    </>
                }
                actions={<LeadActions lead={lead} approval={approval} options={options} defaultFollowUpTime={defaultFollowUpTime} can={can} />}
            />

            <StatusAlerts lead={lead} canViewTenant={can.viewTenant} />
            <DuplicatesAlert matches={duplicates} canViewTenants={can.viewTenant} />

            <div className="grid items-start gap-6 lg:grid-cols-3">
                <div className="grid gap-6 lg:col-start-3 lg:row-start-1">
                    <SectionCard title="Contact">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Name', value: lead.contactName },
                                {
                                    label: 'Email',
                                    value: lead.email && (
                                        <a href={`mailto:${lead.email}`} className="text-primary break-all hover:underline">
                                            {lead.email}
                                        </a>
                                    ),
                                },
                                {
                                    label: 'Phone',
                                    value: lead.phone && (
                                        <a href={telHref(lead.phone)} className="text-primary tabular-nums hover:underline">
                                            {lead.phone}
                                        </a>
                                    ),
                                },
                                { label: 'Town', value: lead.town },
                                { label: 'Postcode', value: lead.postcode },
                                { label: 'News and offers', value: lead.consentMarketing ? 'Agreed' : 'Not agreed' },
                            ]}
                        />
                    </SectionCard>

                    <SectionCard title="Business">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Type', value: businessTypeLabels[lead.businessType] },
                                { label: 'Shops', value: <span className="tabular-nums">{lead.shopsCount}</span> },
                                { label: 'Tills', value: <span className="tabular-nums">{lead.tillsCount}</span> },
                                { label: 'Current system', value: lead.currentSystem },
                            ]}
                        />
                        {lead.message && (
                            <figure className="mt-4 border-t pt-4">
                                <figcaption className="text-muted-foreground mb-1.5 text-[13px]">What they told us</figcaption>
                                <blockquote className="text-sm leading-6 break-words whitespace-pre-line">{lead.message}</blockquote>
                            </figure>
                        )}
                    </SectionCard>

                    <SectionCard title="Lead">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Status', value: <LeadStatusBadge status={lead.status} /> },
                                { label: 'Source', value: leadSourceLabels[lead.source] },
                                { label: 'Received', value: formatDateTimeShort(lead.createdAt) },
                                { label: 'First contacted', value: lead.contactedAt ? formatDateTimeShort(lead.contactedAt) : null },
                                ...(lead.lastContactedAt && lead.lastContactedAt !== lead.contactedAt
                                    ? [{ label: 'Last contacted', value: formatDateTimeShort(lead.lastContactedAt) }]
                                    : []),
                                ...(lead.company
                                    ? [
                                          {
                                              label: 'Tenant',
                                              value: can.viewTenant ? (
                                                  <Link href={route('admin.tenants.show', lead.company.id)} className="text-primary hover:underline">
                                                      {lead.company.name}
                                                  </Link>
                                              ) : (
                                                  lead.company.name
                                              ),
                                          },
                                          { label: 'Tenant status', value: <StatusBadge status={lead.company.status} /> },
                                      ]
                                    : []),
                                ...(utm.length > 0 ? [{ label: 'Campaign', value: <span className="text-[13px]">{utm.join(', ')}</span> }] : []),
                                ...(lead.ip ? [{ label: 'Sent from', value: lead.ip, mono: true }] : []),
                            ]}
                        />
                    </SectionCard>
                </div>

                <div className="lg:col-span-2 lg:col-start-1 lg:row-start-1">
                    <LeadTimeline leadId={lead.id} notes={notes} canWrite={can.update && !lead.archived} authorName={admin.name} />
                </div>
            </div>
        </AdminLayout>
    );
}
