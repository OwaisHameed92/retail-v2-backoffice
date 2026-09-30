import { FieldComparisonCard } from '@/components/app/sync/field-comparison';
import { ConflictStatusBadge, formatDateTimeShort, kindHelp, KindPill } from '@/components/app/sync/format';
import { ResolveCard } from '@/components/app/sync/resolve-card';
import { type ConflictDetailProps } from '@/components/app/sync/types';
import { DescriptionList } from '@/components/shared/description-list';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { CircleCheck, GitCompareArrows, Info } from 'lucide-react';

export default function SyncConflict({ conflict, fields, resolutions }: ConflictDetailProps) {
    const title = conflict.subject ?? conflict.entityLabel;
    const hubRow = ['hubEditNewer', 'hubVersionNewer', 'branchEditNewer'].includes(conflict.kind);

    return (
        <AppLayout>
            <Head title={`Conflict: ${title}`} />

            <PageHeader
                title={title}
                back={{ href: route('app.sync.conflicts.index'), label: 'Sync conflicts' }}
                status={<ConflictStatusBadge status={conflict.status} />}
                media={<InitialsAvatar name={title} shape="square" size="lg" icon={GitCompareArrows} />}
                description={`${conflict.entityLabel}${conflict.branch ? ` · changed at ${conflict.branch}` : ''}`}
                meta={<KindPill kind={conflict.kind} label={conflict.kindLabel} />}
            />

            <Alert variant="info">
                <Info />
                <AlertTitle>{conflict.kindLabel}</AlertTitle>
                <AlertDescription>
                    <p>{kindHelp[conflict.kind]}</p>
                    {conflict.detail && <p className="text-muted-foreground mt-1">{conflict.detail}</p>}
                </AlertDescription>
            </Alert>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    <FieldComparisonCard
                        fields={fields}
                        title={hubRow ? 'Portal and shop versions' : 'What the shop sent'}
                        description={
                            conflict.rowExists
                                ? 'Highlighted fields differ. The portal column is what every till has now.'
                                : "The portal no longer holds this record, so only the shop's version is shown."
                        }
                        portalLabel="Portal (kept)"
                        otherLabel={`Shop${conflict.branch ? ` (${conflict.branch})` : ''}`}
                        emptyText="The shop's version matches the portal's in every field shown."
                    />

                    {conflict.status === 'open' && resolutions.length > 0 && <ResolveCard conflict={conflict} resolutions={resolutions} />}

                    {conflict.status === 'resolved' && (
                        <Alert variant="success">
                            <CircleCheck />
                            <AlertTitle>{conflict.resolutionLabel ?? 'Resolved'}</AlertTitle>
                            <AlertDescription>
                                <p>
                                    {conflict.resolvedBy ? `By ${conflict.resolvedBy}` : 'Resolved'} on {formatDateTimeShort(conflict.resolvedAt)}.
                                </p>
                                {conflict.resolutionNote && <p className="mt-1">“{conflict.resolutionNote}”</p>}
                            </AlertDescription>
                        </Alert>
                    )}
                </div>

                <SectionCard title="Details">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Record', value: conflict.entityLabel },
                            { label: 'Id', value: conflict.entityId, mono: true },
                            { label: 'Shop', value: conflict.branch },
                            { label: 'Changed at the shop', value: formatDateTimeShort(conflict.incomingAt, '') },
                            { label: 'Reached the portal', value: formatDateTimeShort(conflict.receivedAt, '') },
                            { label: "Shop's version", value: String(conflict.incomingVersion) },
                            { label: "Portal's version", value: conflict.localVersion === null ? null : String(conflict.localVersion) },
                            { label: 'Change number', value: conflict.incomingSeq === null ? null : String(conflict.incomingSeq) },
                        ]}
                    />
                </SectionCard>
            </div>
        </AppLayout>
    );
}
