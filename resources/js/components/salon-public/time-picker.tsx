import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { CalendarX2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { salonSend } from './api';
import { type FreeDay } from './types';

export interface PickedTime {
    date: string;
    time: string;
    label: string;
}

/**
 * Days and start times from the till's published ranges (W3a, README §10), fetched for the client's basket and stylist
 * (`url` carries them). Times are the shop's (UK). A day with no free start is not listed. Keyboard: every day and
 * time is a button; the chosen one is `aria-pressed`.
 */
export function TimePicker({
    url,
    value,
    onChange,
}: {
    url: string | null;
    value: PickedTime | null;
    onChange: (picked: PickedTime | null) => void;
}) {
    const [days, setDays] = useState<FreeDay[] | null>(null);
    const [day, setDay] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (url === null) {
            setDays(null);
            return;
        }

        const controller = new AbortController();
        setDays(null);
        setError(null);

        salonSend<{ days: FreeDay[] }>('GET', url, undefined, controller.signal)
            .then((result) => {
                const list = result.data?.days ?? [];
                setDays(list);
                setError(result.ok ? null : result.message);
                setDay((current) => (current !== null && list.some((d) => d.date === current) ? current : (list[0]?.date ?? null)));
            })
            .catch(() => undefined);

        return () => controller.abort();
    }, [url]);

    // A time that is no longer offered for the new choice is cleared.
    useEffect(() => {
        if (value !== null && days !== null && !days.some((d) => d.date === value.date && d.times.includes(value.time))) {
            onChange(null);
        }
    }, [days, value, onChange]);

    if (url === null) {
        return <p className="text-muted-foreground">Choose a service first.</p>;
    }

    if (days === null) {
        return (
            <div className="space-y-3" aria-busy="true" aria-label="Loading free times">
                <Skeleton className="h-14 w-full" />
                <Skeleton className="h-24 w-full" />
            </div>
        );
    }

    if (days.length === 0) {
        return (
            <div className="text-muted-foreground flex items-start gap-2 rounded-lg border border-dashed p-4">
                <CalendarX2 className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <p>{error ?? 'No free times online for this choice. Try another stylist, or ring the shop.'}</p>
            </div>
        );
    }

    const current = days.find((d) => d.date === day) ?? days[0];

    return (
        <div className="space-y-4">
            <div role="group" aria-label="Day" className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                {days.map((d) => {
                    const [weekday, ...rest] = d.label.split(' ');

                    return (
                        <button
                            key={d.date}
                            type="button"
                            aria-pressed={d.date === current.date}
                            onClick={() => setDay(d.date)}
                            className={cn(
                                'focus-visible:ring-ring/30 flex min-w-16 shrink-0 flex-col items-center rounded-lg border px-3 py-2 outline-none focus-visible:ring-[3px]',
                                d.date === current.date
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border bg-card hover:border-primary/60',
                            )}
                        >
                            <span className="text-xs">{weekday}</span>
                            <span className="font-semibold tabular-nums">{rest.join(' ')}</span>
                        </button>
                    );
                })}
            </div>

            <div role="group" aria-label={`Times on ${current.label}`} className="grid grid-cols-4 gap-2 sm:grid-cols-6">
                {current.times.map((time) => {
                    const picked = value?.date === current.date && value.time === time;

                    return (
                        <button
                            key={time}
                            type="button"
                            aria-pressed={picked}
                            onClick={() => onChange({ date: current.date, time, label: `${current.label}, ${time}` })}
                            className={cn(
                                'focus-visible:ring-ring/30 rounded-lg border py-2 text-center font-medium tabular-nums outline-none focus-visible:ring-[3px]',
                                picked ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card hover:border-primary/60',
                            )}
                        >
                            {time}
                        </button>
                    );
                })}
            </div>
            <p className="text-muted-foreground text-xs">Times are UK time at the shop.</p>
        </div>
    );
}
