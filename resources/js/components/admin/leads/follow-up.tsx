import { cn } from '@/lib/utils';
import { AlarmClock, CalendarClock } from 'lucide-react';
import { followUpState, formatFollowUp } from './format';

interface FollowUpProps {
    at: string | null;
    /** Muted text when there is no follow-up. */
    empty?: string;
    className?: string;
}

/** A follow-up time: red with an alarm icon when overdue, amber when it is today, plain otherwise. */
export function FollowUp({ at, empty = 'None', className }: FollowUpProps) {
    if (!at) {
        return <span className={cn('text-muted-foreground', className)}>{empty}</span>;
    }
    const state = followUpState(at);
    const Icon = state === 'upcoming' ? CalendarClock : AlarmClock;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 whitespace-nowrap tabular-nums',
                state === 'overdue' && 'text-danger-foreground font-medium',
                state === 'today' && 'text-warning-foreground font-medium',
                className,
            )}
        >
            <Icon className={cn('size-3.5 shrink-0', state === 'upcoming' && 'text-muted-foreground')} aria-hidden />
            {formatFollowUp(at)}
            {state === 'overdue' && <span className="sr-only">(overdue)</span>}
        </span>
    );
}
