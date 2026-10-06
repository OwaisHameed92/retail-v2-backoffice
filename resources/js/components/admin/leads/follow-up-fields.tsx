import { FormField, FormGrid } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { dateLocale, timeZoneLabel, zonedDateFormat } from '@/lib/country';

/** "2026-09-25" for an ISO time, or tomorrow when empty (the profile's time zone). */
export function dateInputValue(iso: string | null | undefined, fallbackDays = 1): string {
    const date = iso ? new Date(iso) : new Date(Date.now() + fallbackDays * 86_400_000);

    return zonedDateFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

/** "14:30" for an ISO time (the profile's time zone), or the fallback. */
export function timeInputValue(iso: string | null | undefined, fallback: string): string {
    return iso ? zonedDateFormat(dateLocale(), { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)) : fallback;
}

export function todayInputValue(): string {
    return dateInputValue(null, 0);
}

interface FollowUpFieldsProps {
    idPrefix: string;
    date: string;
    time: string;
    onDate: (value: string) => void;
    onTime: (value: string) => void;
    dateError?: string;
    timeError?: string;
    dateLabel?: string;
    optional?: boolean;
}

/** Date + time inputs for a follow-up (shop time). */
export function FollowUpFields({
    idPrefix,
    date,
    time,
    onDate,
    onTime,
    dateError,
    timeError,
    dateLabel = 'Date',
    optional = false,
}: FollowUpFieldsProps) {
    return (
        <FormGrid>
            <FormField id={`${idPrefix}-date`} label={dateLabel} optional={optional} error={dateError}>
                <Input
                    id={`${idPrefix}-date`}
                    type="date"
                    min={todayInputValue()}
                    value={date}
                    onChange={(event) => onDate(event.target.value)}
                    aria-invalid={!!dateError}
                />
            </FormField>
            <FormField id={`${idPrefix}-time`} label="Time" help={`${timeZoneLabel()} time`} error={timeError}>
                <Input
                    id={`${idPrefix}-time`}
                    type="time"
                    value={time}
                    disabled={!date}
                    onChange={(event) => onTime(event.target.value)}
                    aria-invalid={!!timeError}
                />
            </FormField>
        </FormGrid>
    );
}
