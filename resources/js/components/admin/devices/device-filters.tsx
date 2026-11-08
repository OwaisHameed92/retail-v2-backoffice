import { type DeviceKind, type DevicesIndexProps } from '@/components/admin/devices/types';
import { useTableQuery } from '@/components/shared/data-table';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Toggle } from '@/components/ui/toggle';
import { formatNumber } from '@/lib/country';
import { cn } from '@/lib/utils';
import { CalendarX2, History } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

export const DEVICES_ONLY = ['devices', 'summary', 'filters', 'versions'];

const DAY_CHOICES = [1, 3, 7, 14, 30, 60, 90];

/** Same rule as the server (AppVersion): 0.1.85, v1.2, 1.0.0-beta.2. */
const VERSION = /^v?\d+(\.\d+){0,2}(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/;

const groupLabels: Record<DeviceKind, string> = { till: 'Tills', handheld: 'Handhelds' };

/** Status, old EPOS version and "not seen in" filters for the Devices list. All live in the URL. */
export function DeviceFilters({ filters, statuses, versions }: Pick<DevicesIndexProps, 'filters' | 'statuses' | 'versions'>) {
    const { update } = useTableQuery({ only: DEVICES_ONLY });
    const listId = useId();
    const [below, setBelow] = useState(filters.below ?? '');
    const belowValid = below.trim() === '' || VERSION.test(below.trim());
    const kinds = (Object.keys(groupLabels) as DeviceKind[]).filter((kind) => filters.kind === null || filters.kind === kind);
    const days = DAY_CHOICES.includes(filters.days) ? DAY_CHOICES : [...DAY_CHOICES, filters.days].sort((a, b) => a - b);

    useEffect(() => setBelow(filters.below ?? ''), [filters.below]);

    const commitBelow = () => {
        const value = below.trim();
        if (belowValid && value !== (filters.below ?? '')) {
            update({ below: value || undefined, page: 1 });
        }
    };

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
            <Select value={filters.status ?? 'all'} onValueChange={(next) => update({ status: next === 'all' ? undefined : next, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-44" aria-label="Filter by status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Live devices</SelectItem>
                    {kinds.map((kind) => (
                        <SelectGroup key={kind}>
                            <SelectLabel>{groupLabels[kind]}</SelectLabel>
                            {statuses
                                .filter((status) => status.kind === kind)
                                .map((status) => (
                                    <SelectItem key={status.value} value={status.value}>
                                        {status.label}
                                    </SelectItem>
                                ))}
                        </SelectGroup>
                    ))}
                </SelectContent>
            </Select>

            <div className={cn('flex h-9 items-center rounded-md border', filters.old && 'border-primary/40 bg-primary-soft/40')}>
                <Toggle
                    size="sm"
                    pressed={filters.old}
                    onPressedChange={(pressed) => update({ old: pressed ? 1 : undefined, below: pressed ? filters.below : undefined, page: 1 })}
                    className="data-[state=on]:text-foreground h-full rounded-r-none border-0 data-[state=on]:bg-transparent"
                    aria-label="Only tills on an old EPOS version"
                >
                    <History />
                    Old EPOS version
                </Toggle>
                {filters.old && (
                    <label className="flex h-full items-center gap-1.5 border-l pr-1 pl-2 text-sm">
                        <span className="text-muted-foreground whitespace-nowrap">older than</span>
                        <Input
                            value={below}
                            onChange={(event) => setBelow(event.target.value)}
                            onBlur={commitBelow}
                            onKeyDown={(event) => event.key === 'Enter' && commitBelow()}
                            placeholder={filters.gate ?? '0.1.0'}
                            list={listId}
                            inputMode="decimal"
                            aria-invalid={!belowValid}
                            aria-label="EPOS version to compare with"
                            title={belowValid ? 'Leave empty for the newest version any till runs' : 'Type a version such as 0.1.85'}
                            className="h-7 w-24 font-mono text-[13px]"
                        />
                        <datalist id={listId}>
                            {versions.map((version) => (
                                <option key={version.version} value={version.version}>
                                    {`${formatNumber(version.tills)} ${version.tills === 1 ? 'till' : 'tills'}`}
                                </option>
                            ))}
                        </datalist>
                    </label>
                )}
            </div>

            <div className={cn('flex h-9 items-center rounded-md border', filters.unseen && 'border-primary/40 bg-primary-soft/40')}>
                <Toggle
                    size="sm"
                    pressed={filters.unseen}
                    onPressedChange={(pressed) => update({ unseen: pressed ? 1 : undefined, days: undefined, page: 1 })}
                    className="data-[state=on]:text-foreground h-full rounded-r-none border-0 data-[state=on]:bg-transparent"
                    aria-label="Only devices not seen recently"
                >
                    <CalendarX2 />
                    Not seen in
                    {!filters.unseen && ` ${filters.days} days`}
                </Toggle>
                {filters.unseen && (
                    <Select value={String(filters.days)} onValueChange={(next) => update({ days: Number(next) === 7 ? undefined : next, page: 1 })}>
                        <SelectTrigger className="h-full w-28 rounded-l-none border-0 border-l shadow-none" aria-label="Days not seen">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {days.map((day) => (
                                <SelectItem key={day} value={String(day)}>
                                    {day === 1 ? '1 day' : `${day} days`}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </div>
        </div>
    );
}
