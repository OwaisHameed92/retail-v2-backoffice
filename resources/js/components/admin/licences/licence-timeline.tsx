import { formatDateTimeShort, formatRelative } from '@/components/admin/licences/format';
import { type TimelineEvent } from '@/components/admin/licences/types';
import { EmptyState } from '@/components/shared/empty-state';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { CalendarClock } from 'lucide-react';

const dotTone: Record<TimelineEvent['tone'], string> = {
    neutral: 'bg-muted-foreground/60',
    success: 'bg-success',
    info: 'bg-primary',
    warning: 'bg-warning',
    danger: 'bg-destructive',
};

/** Key dates of the licence, past and upcoming. */
export function LicenceTimeline({ events }: { events: TimelineEvent[] }) {
    return (
        <Card>
            <CardHeader className="pb-4">
                <h2 className="text-base font-semibold">Timeline</h2>
                <p className="text-muted-foreground text-sm">Key dates, past and upcoming.</p>
            </CardHeader>
            <CardContent>
                {events.length === 0 ? (
                    <EmptyState icon={CalendarClock} title="No dates yet" className="py-6" />
                ) : (
                    <ol className="grid">
                        {events.map((event, index) => (
                            <li key={`${event.key}-${index}`} className="relative flex gap-3 pb-5 last:pb-0">
                                {index < events.length - 1 && <span className="bg-border absolute top-4 bottom-0 left-[5px] w-px" aria-hidden />}
                                <span
                                    className={cn(
                                        'relative mt-1.5 size-[11px] shrink-0 rounded-full ring-4 ring-card',
                                        event.upcoming ? 'border-muted-foreground/50 border-2 border-dashed bg-card' : dotTone[event.tone],
                                    )}
                                    aria-hidden
                                />
                                <div className="min-w-0 flex-1">
                                    <p className={cn('text-sm font-medium', event.upcoming && 'text-muted-foreground')}>
                                        {event.label}
                                        {event.upcoming && <span className="sr-only"> (upcoming)</span>}
                                    </p>
                                    <p className="text-muted-foreground text-xs tabular-nums">
                                        {formatDateTimeShort(event.at)} · {formatRelative(event.at)}
                                    </p>
                                    {event.detail && <p className="text-muted-foreground mt-0.5 text-sm break-words">{event.detail}</p>}
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
