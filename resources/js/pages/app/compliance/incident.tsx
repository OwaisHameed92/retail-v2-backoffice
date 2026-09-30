import { formatDateTime } from '@/components/app/compliance/format';
import { type IncidentProps } from '@/components/app/compliance/types';
import { DescriptionList } from '@/components/shared/description-list';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

/** One incident (module 5.7), as the shop recorded it on the till. Read only. */
export default function ComplianceIncident({ incident }: IncidentProps) {
    const title = incident.category ?? 'Incident';

    return (
        <AppLayout>
            <Head title={`${title} · Incidents`} />
            <PageHeader
                title={title}
                back={{ href: route('app.compliance.incidents'), label: 'Incidents' }}
                status={<StatusPill tone="neutral">Recorded on the till</StatusPill>}
                description={[incident.shop, formatDateTime(incident.occurredAt)].filter(Boolean).join(' · ')}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
                <SectionCard className="lg:col-span-2" title="What happened">
                    <p className="text-sm leading-6 whitespace-pre-line">{incident.description ?? 'No description was recorded.'}</p>
                </SectionCard>
                <SectionCard title="Details">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Happened', value: formatDateTime(incident.occurredAt) },
                            { label: 'Recorded', value: incident.recordedAt ? formatDateTime(incident.recordedAt) : null },
                            { label: 'Shop', value: incident.shop },
                            { label: 'Reported by', value: incident.reportedBy },
                            { label: 'Police reference', value: incident.police, mono: true },
                            { label: 'Insurer reference', value: incident.insurer, mono: true },
                        ]}
                    />
                </SectionCard>
            </div>
        </AppLayout>
    );
}
