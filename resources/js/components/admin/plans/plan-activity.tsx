import { formatDateTime } from '@/components/admin/format';
import { type PlanActivityEntry } from '@/components/admin/plans/types';
import { SectionCard } from '@/components/shared/section-card';
import { Timeline, TimelineChanges, type TimelineTone } from '@/components/shared/timeline';
import { Archive, ArchiveRestore, Copy, History, Pencil, Plus, type LucideIcon } from 'lucide-react';

const icons: Record<string, { icon: LucideIcon; tone: TimelineTone }> = {
    'plan.created': { icon: Plus, tone: 'primary' },
    'plan.updated': { icon: Pencil, tone: 'neutral' },
    'plan.archived': { icon: Archive, tone: 'danger' },
    'plan.restored': { icon: ArchiveRestore, tone: 'success' },
    'plan.duplicated': { icon: Copy, tone: 'neutral' },
};

/** "Activity" card: the plan's audit log, newest first. */
export function PlanActivity({ entries }: { entries: PlanActivityEntry[] }) {
    return (
        <SectionCard title="Activity" description="Who changed this plan, and when.">
            <Timeline
                emptyBody="Changes to this plan will appear here."
                items={entries.map((entry) => ({
                    id: entry.id,
                    icon: icons[entry.action]?.icon ?? History,
                    tone: icons[entry.action]?.tone ?? 'neutral',
                    title: (
                        <>
                            <span className="font-medium">{entry.actorName}</span> <span className="text-muted-foreground">·</span> {entry.summary}
                        </>
                    ),
                    time: formatDateTime(entry.createdAt, ''),
                    body: entry.changes.length > 0 ? <TimelineChanges changes={entry.changes} /> : undefined,
                }))}
            />
        </SectionCard>
    );
}
