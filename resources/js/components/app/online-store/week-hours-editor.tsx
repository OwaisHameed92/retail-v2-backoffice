import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timeZoneLabel } from '@/lib/country';
import { Copy } from 'lucide-react';
import { DAY_NAMES, type ShopDay } from './types';

export type DayForm = { day: ShopDay['day']; open: boolean; from: string; to: string };

export const dayForms = (days: ShopDay[]): DayForm[] =>
    days.map((d) => ({ day: d.day, open: d.open !== null, from: d.open ?? '09:00', to: d.close ?? '17:00' }));

export const weekPayload = (days: DayForm[]) => Object.fromEntries(days.map((d) => [d.day, d.open ? { open: d.from, close: d.to } : null]));

/** A week of hours (shop time): a tick per day and from/to times, with "use Monday's hours every day". */
export function WeekHoursEditor({
    idPrefix,
    title,
    days,
    onChange,
    errors,
    errorKey,
    disabled,
    closedText,
}: {
    idPrefix: string;
    title: string;
    days: DayForm[];
    onChange: (days: DayForm[]) => void;
    errors: Record<string, string | undefined>;
    /** The form field the server reports errors under ("delivery_hours"). */
    errorKey: string;
    disabled: boolean;
    closedText: string;
}) {
    const setDay = (i: number, patch: Partial<DayForm>) => onChange(days.map((d, j) => (j === i ? { ...d, ...patch } : d)));

    return (
        <div className="grid content-start gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-semibold">{title}</p>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={disabled}
                    onClick={() => onChange(days.map((d) => ({ ...days[0], day: d.day })))}
                >
                    <Copy />
                    Use Monday&apos;s hours every day
                </Button>
            </div>
            <p className="text-muted-foreground text-xs">{timeZoneLabel()} time.</p>
            <div className="divide-border grid divide-y rounded-lg border">
                {days.map((d, i) => {
                    const error = errors[`${errorKey}.${d.day}.close`] ?? errors[`${errorKey}.${d.day}.open`] ?? errors[`${errorKey}.${d.day}`];

                    return (
                        <div key={d.day} className="grid grid-cols-1 gap-2 px-3 py-2 sm:grid-cols-[8rem_1fr] sm:items-center">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id={`${idPrefix}-${d.day}`}
                                    checked={d.open}
                                    disabled={disabled}
                                    onCheckedChange={(on) => setDay(i, { open: on === true })}
                                />
                                <Label htmlFor={`${idPrefix}-${d.day}`} className="font-medium">
                                    {DAY_NAMES[d.day]}
                                </Label>
                            </div>
                            {d.open ? (
                                <div className="grid gap-1">
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="time"
                                            className="h-9 w-32"
                                            value={d.from}
                                            disabled={disabled}
                                            aria-label={`${DAY_NAMES[d.day]} from`}
                                            aria-invalid={!!error}
                                            onChange={(e) => setDay(i, { from: e.target.value })}
                                        />
                                        <span className="text-muted-foreground text-sm">to</span>
                                        <Input
                                            type="time"
                                            className="h-9 w-32"
                                            value={d.to}
                                            disabled={disabled}
                                            aria-label={`${DAY_NAMES[d.day]} to`}
                                            aria-invalid={!!error}
                                            onChange={(e) => setDay(i, { to: e.target.value })}
                                        />
                                    </div>
                                    {error && <p className="text-danger-foreground text-xs">{error}</p>}
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-sm">{closedText}</p>
                            )}
                        </div>
                    );
                })}
            </div>
            {errors[errorKey] && <p className="text-danger-foreground text-xs">{errors[errorKey]}</p>}
        </div>
    );
}
