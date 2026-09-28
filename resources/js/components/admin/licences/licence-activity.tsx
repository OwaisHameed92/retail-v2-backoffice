import { formatDateTimeShort } from '@/components/admin/licences/format';
import { type LicenceActivityEntry } from '@/components/admin/licences/types';
import { EmptyState } from '@/components/shared/empty-state';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    Ban,
    CalendarPlus,
    CirclePause,
    CirclePlay,
    History,
    KeyRound,
    Layers,
    Mail,
    MonitorCheck,
    MonitorX,
    NotebookPen,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    type LucideIcon,
} from 'lucide-react';

const icons: Record<string, LucideIcon> = {
    'licence.issued': Sparkles,
    'licence.key_reissued': KeyRound,
    'licence.device_reset': MonitorX,
    'licence.device_released': MonitorX,
    'licence.lock_changed': MonitorX,
    'licence.suspended': CirclePause,
    'licence.unsuspended': CirclePlay,
    'licence.revoked': Ban,
    'licence.renewed': CalendarPlus,
    'licence.plan_changed': Layers,
    'licence.status_changed': RefreshCw,
    'licence.key_emailed': Mail,
    'licence.notes_updated': NotebookPen,
    'licence.activated': MonitorCheck,
    'licence.device_bound': MonitorCheck,
    'licence.reinstalled': MonitorCheck,
    'licence.released': MonitorX,
    'licence.app_updated': RefreshCw,
    'licence.alert_resolved': ShieldCheck,
};

const danger = new Set(['licence.suspended', 'licence.revoked']);

/** "Activity" card: this licence's audit log, newest first. */
export function LicenceActivity({ entries }: { entries: LicenceActivityEntry[] }) {
    return (
        <Card>
            <CardHeader className="pb-4">
                <h2 className="text-base font-semibold">Activity</h2>
                <p className="text-muted-foreground text-sm">Who changed this licence, and when.</p>
            </CardHeader>
            <CardContent>
                {entries.length === 0 ? (
                    <EmptyState icon={History} title="No activity yet" body="Changes to this licence appear here." className="py-6" />
                ) : (
                    <ol className="grid">
                        {entries.map((entry, index) => {
                            const Icon = icons[entry.action] ?? History;

                            return (
                                <li key={entry.id} className="relative flex gap-3 pb-5 last:pb-0">
                                    {index < entries.length - 1 && <span className="bg-border absolute top-8 bottom-0 left-4 w-px" aria-hidden />}
                                    <span
                                        className={cn(
                                            'bg-card text-muted-foreground relative flex size-8 shrink-0 items-center justify-center rounded-full border',
                                            danger.has(entry.action) && 'bg-danger-soft text-destructive',
                                        )}
                                    >
                                        <Icon className="size-4" aria-hidden />
                                    </span>
                                    <div className="min-w-0 flex-1 pt-1">
                                        <p className="text-sm break-words">
                                            <span className="font-medium">{entry.actorName}</span> <span className="text-muted-foreground">·</span>{' '}
                                            {entry.description}
                                        </p>
                                        <p className="text-muted-foreground text-xs tabular-nums">{formatDateTimeShort(entry.createdAt)}</p>
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
