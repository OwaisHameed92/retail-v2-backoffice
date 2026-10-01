import { type TableParams } from '@/components/shared/data-table';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type ReactNode } from 'react';
import { type AuditFilterValues, type AuditLogProps } from './types';

/** Changing a filter always starts again from the newest entries. */
export const resetCursor = { after: undefined, before: undefined } as const;

interface AuditFiltersProps {
    filters: AuditFilterValues;
    options: AuditLogProps['options'];
    update: (params: TableParams) => void;
    /** "Switch & Save" and "System" in the tenant view; "System" only for admins. */
    tenantView: boolean;
    /** Extra controls first (the admin business picker). */
    before?: ReactNode;
}

function OptionSelect({
    value,
    onChange,
    all,
    label,
    children,
    width = 'sm:w-48',
}: {
    value: string | null;
    onChange: (value: string | undefined) => void;
    all: string;
    label: string;
    children: ReactNode;
    width?: string;
}) {
    return (
        <Select value={value ?? 'all'} onValueChange={(next) => onChange(next === 'all' ? undefined : next)}>
            <SelectTrigger className={`h-9 w-full ${width}`} aria-label={label}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent className="max-h-80">
                <SelectItem value="all">{all}</SelectItem>
                {children}
            </SelectContent>
        </Select>
    );
}

/** Who, what kind of action, which kind of record and which days. Every value lives in the URL. */
export function AuditFilters({ filters, options, update, tenantView, before }: AuditFiltersProps) {
    const groups = options.actions.filter((a) => a.group);
    const actions = options.actions.filter((a) => !a.group);

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
            {before}

            <OptionSelect value={filters.actor} onChange={(actor) => update({ actor, ...resetCursor })} all="Anyone" label="Who">
                <SelectGroup>
                    <SelectLabel>Kind</SelectLabel>
                    {tenantView && <SelectItem value="staff">Switch &amp; Save</SelectItem>}
                    <SelectItem value="system">System (automatic)</SelectItem>
                </SelectGroup>
                {options.actors.length > 0 && (
                    <>
                        <SelectSeparator />
                        <SelectGroup>
                            <SelectLabel>{tenantView ? 'People in your business' : 'Admins'}</SelectLabel>
                            {options.actors.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </>
                )}
            </OptionSelect>

            <OptionSelect
                value={filters.action}
                onChange={(action) => update({ action, ...resetCursor })}
                all="Every action"
                label="Action"
                width="sm:w-56"
            >
                {groups.length > 0 && (
                    <SelectGroup>
                        <SelectLabel>Groups</SelectLabel>
                        {groups.map((o) => (
                            <SelectItem key={o.value} value={o.value}>
                                {o.label}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                )}
                {actions.length > 0 && (
                    <>
                        <SelectSeparator />
                        <SelectGroup>
                            <SelectLabel>Actions</SelectLabel>
                            {actions.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </>
                )}
            </OptionSelect>

            {options.subjectTypes.length > 0 && (
                <OptionSelect
                    value={filters.subjectType}
                    onChange={(subjectType) => update({ subjectType, subjectId: undefined, ...resetCursor })}
                    all="Every record"
                    label="Record type"
                    width="sm:w-44"
                >
                    {options.subjectTypes.map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                            {o.label}
                        </SelectItem>
                    ))}
                </OptionSelect>
            )}

            <div className="flex items-center gap-1.5">
                <Input
                    type="date"
                    className="h-9 w-full sm:w-38"
                    aria-label="From day"
                    value={filters.from ?? ''}
                    max={filters.to ?? undefined}
                    onChange={(e) => update({ from: e.target.value || undefined, ...resetCursor })}
                />
                <span className="text-muted-foreground text-sm">to</span>
                <Input
                    type="date"
                    className="h-9 w-full sm:w-38"
                    aria-label="To day"
                    value={filters.to ?? ''}
                    min={filters.from ?? undefined}
                    onChange={(e) => update({ to: e.target.value || undefined, ...resetCursor })}
                />
            </div>
        </div>
    );
}
