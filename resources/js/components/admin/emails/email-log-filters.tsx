import { useTableQuery } from '@/components/shared/data-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { X } from 'lucide-react';
import { type EmailLogFilters as Filters, type Option } from './types';

const ALL = 'all';
const ONLY = ['logs', 'filters', 'templateOptions', 'summary'];

interface EmailLogFiltersProps {
    filters: Filters;
    templateOptions: Option[];
    statusOptions: Option[];
}

/** Template, status and date range filters for the email log. Every value lives in the URL. */
export function EmailLogFilters({ filters, templateOptions, statusOptions }: EmailLogFiltersProps) {
    const { update } = useTableQuery({ only: ONLY });
    const active = Boolean(filters.template || filters.status || filters.from || filters.to);

    return (
        <>
            <Select value={filters.template ?? ALL} onValueChange={(value) => update({ template: value === ALL ? undefined : value, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-56" aria-label="Filter by template">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>All templates</SelectItem>
                    {templateOptions.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select value={filters.status ?? ALL} onValueChange={(value) => update({ status: value === ALL ? undefined : value, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-36" aria-label="Filter by status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>All statuses</SelectItem>
                    {statusOptions.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <div className="flex items-center gap-2">
                <Input
                    type="date"
                    value={filters.from ?? ''}
                    max={filters.to ?? undefined}
                    onChange={(event) => update({ from: event.target.value || undefined, page: 1 })}
                    aria-label="Sent from"
                    className="h-9 w-full sm:w-38"
                />
                <span className="text-muted-foreground text-sm">to</span>
                <Input
                    type="date"
                    value={filters.to ?? ''}
                    min={filters.from ?? undefined}
                    onChange={(event) => update({ to: event.target.value || undefined, page: 1 })}
                    aria-label="Sent to"
                    className="h-9 w-full sm:w-38"
                />
            </div>

            {active && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-9 self-start sm:self-auto"
                    onClick={() => update({ template: undefined, status: undefined, from: undefined, to: undefined, page: 1 })}
                >
                    <X />
                    Clear filters
                </Button>
            )}
        </>
    );
}

export default EmailLogFilters;
