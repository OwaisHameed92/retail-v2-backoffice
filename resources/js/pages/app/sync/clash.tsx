import { FieldComparisonCard } from '@/components/app/sync/field-comparison';
import { ClashBadge, formatDateTimeShort } from '@/components/app/sync/format';
import { type ClashDetailProps } from '@/components/app/sync/types';
import { DescriptionList } from '@/components/shared/description-list';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Info, Store } from 'lucide-react';

export default function SyncClash({ clash, hubChange, fields }: ClashDetailProps) {
    const title = clash.subject ?? clash.entityLabel;

    return (
        <AppLayout>
            <Head title={`Shop clash: ${title}`} />

            <PageHeader
                title={title}
                back={{ href: `${route('app.sync.conflicts.index')}?tab=shop`, label: 'Shop clashes' }}
                status={<ClashBadge resolution={clash.resolution} label={clash.resolutionLabel} />}
                media={<InitialsAvatar name={title} shape="square" size="lg" icon={Store} />}
                description={`${clash.entityLabel}${clash.branch ? ` · at ${clash.branch}` : ''}`}
            />

            <Alert variant="info">
                <Info />
                <AlertTitle>Settled at the shop</AlertTitle>
                <AlertDescription>
                    <p>
                        The till kept the portal's change aside because someone at the shop had edited the same thing and not synced yet. Someone at
                        the shop chooses "Head office wins" or "This shop wins" on the till's Sync status screen; the portal cannot settle it.
                    </p>
                    {clash.detail && <p className="text-muted-foreground mt-1">{clash.detail}</p>}
                </AlertDescription>
            </Alert>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <FieldComparisonCard
                    fields={fields}
                    title="Portal now and the change the till kept aside"
                    description={
                        hubChange
                            ? "Highlighted fields differ between the portal's row today and the change the till is holding."
                            : 'This till is older than contract 1.4 and did not send the change it kept aside.'
                    }
                    portalLabel="Portal now"
                    otherLabel="Held at the till"
                    emptyText={hubChange ? 'The portal still holds exactly the change the till kept aside.' : 'Nothing to compare.'}
                />

                <SectionCard title="Details">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Record', value: clash.entityLabel },
                            { label: 'Id', value: clash.entityId, mono: true },
                            { label: 'Shop', value: clash.branch },
                            { label: 'Detected', value: formatDateTimeShort(clash.detectedAt, '') },
                            { label: 'Settled', value: formatDateTimeShort(clash.resolvedAt, '') },
                            { label: 'Portal version held', value: String(hubChange?.version ?? clash.hubVersion) },
                            { label: "Shop's version", value: String(clash.branchVersion) },
                        ]}
                    />
                </SectionCard>
            </div>
        </AppLayout>
    );
}
