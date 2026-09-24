import { formatDateTime } from '@/components/admin/format';
import { type PlanActivityEntry } from '@/components/admin/plans/types';
import { EmptyState } from '@/components/shared/empty-state';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Archive, ArchiveRestore, Copy, History, Pencil, Plus, type LucideIcon } from 'lucide-react';

const icons: Record<string, LucideIcon> = {
    'plan.created': Plus,
    'plan.updated': Pencil,
    'plan.archived': Archive,
    'plan.restored': ArchiveRestore,
    'plan.duplicated': Copy,
};

/** "Activity" card: the plan's audit log, newest first. */
export function PlanActivity({ entries }: { entries: PlanActivityEntry[] }) {
    return (
        <Card>
            <CardHeader className="pb-4">
                <h2 className="text-base font-semibold">Activity</h2>
                <p className="text-muted-foreground text-sm">Who changed this plan, and when.</p>
            </CardHeader>
            <CardContent>
                {entries.length === 0 ? (
                    <EmptyState icon={History} title="No activity yet" body="Changes to this plan will appear here." className="py-6" />
                ) : (
                    <ol className="grid gap-0">
                        {entries.map((entry, index) => {
                            const Icon = icons[entry.action] ?? History;
                            const last = index === entries.length - 1;

                            return (
                                <li key={entry.id} className="relative flex gap-3 pb-5 last:pb-0">
                                    {!last && <span className="bg-border absolute top-8 bottom-0 left-4 w-px" aria-hidden />}
                                    <span
                                        className={cn(
                                            'bg-card text-muted-foreground relative flex size-8 shrink-0 items-center justify-center rounded-full border',
                                            entry.action === 'plan.archived' && 'bg-danger-soft text-destructive',
                                        )}
                                    >
                                        <Icon className="size-4" aria-hidden />
                                    </span>
                                    <div className="min-w-0 flex-1 pt-1">
                                        <p className="text-sm">
                                            <span className="font-medium">{entry.actorName}</span> <span className="text-muted-foreground">·</span>{' '}
                                            {entry.summary}
                                        </p>
                                        <p className="text-muted-foreground text-xs tabular-nums">{formatDateTime(entry.createdAt, '')}</p>
                                        {entry.changes.length > 0 && (
                                            <dl className="bg-muted/60 mt-2 grid gap-1 rounded-md p-2.5 text-sm">
                                                {entry.changes.map((change) => (
                                                    <div key={change.label} className="grid gap-x-2 sm:grid-cols-[11rem_minmax(0,1fr)]">
                                                        <dt className="text-muted-foreground">{change.label}</dt>
                                                        <dd className="min-w-0 break-words">
                                                            {change.from !== null && (
                                                                <>
                                                                    <span className="text-muted-foreground line-through">{change.from}</span>
                                                                    <span className="text-muted-foreground px-1.5" aria-label="changed to">
                                                                        →
                                                                    </span>
                                                                </>
                                                            )}
                                                            <span>{change.to}</span>
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
