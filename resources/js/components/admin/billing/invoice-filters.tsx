import { invoiceStatusLabels } from '@/components/admin/billing/format';
import { type InvoiceIndexProps, type InvoiceStatus } from '@/components/admin/billing/types';
import { BusinessPicker } from '@/components/admin/licences/licence-filters';
import { useTableQuery } from '@/components/shared/data-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Building2, X } from 'lucide-react';
import { useState } from 'react';

const ONLY = ['invoices', 'filters', 'totals', 'counts'];
const number = new Intl.NumberFormat('en-GB');

/** Status (with counts), business and issue date filters for the invoice list. All live in the URL. */
export function InvoiceFilters({ filters, statuses, counts }: Pick<InvoiceIndexProps, 'filters' | 'statuses' | 'counts'>) {
    const { update } = useTableQuery({ only: ONLY });
    const [picking, setPicking] = useState(false);
    const openCount = (counts.issued ?? 0) + (counts.partiallyPaid ?? 0) + (counts.overdue ?? 0);
    const active = Boolean(filters.status || filters.company || filters.from || filters.to);

    return (
        <>
            <Select value={filters.status ?? 'all'} onValueChange={(next) => update({ status: next === 'all' ? undefined : next, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-44" aria-label="Filter by status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem value="open">
                        Unpaid<span className="text-muted-foreground ml-1 tabular-nums">({number.format(openCount)})</span>
                    </SelectItem>
                    {statuses.map((status) => (
                        <SelectItem key={status.value} value={status.value}>
                            {invoiceStatusLabels[status.value as InvoiceStatus]}
                            <span className="text-muted-foreground ml-1 tabular-nums">({number.format(counts[status.value] ?? 0)})</span>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {filters.company ? (
                <span className="bg-accent inline-flex h-9 max-w-full items-center gap-1 rounded-md border pr-1 pl-3 text-sm">
                    <Building2 className="text-muted-foreground size-4 shrink-0" aria-hidden />
                    <span className="truncate">{filters.company.name}</span>
                    <Button variant="ghost" size="icon" className="size-7" aria-label={`Stop filtering by ${filters.company.name}`} onClick={() => update({ company: undefined, page: 1 })}>
                        <X className="size-4" />
                    </Button>
                </span>
            ) : (
                <Button variant="outline" className="h-9 justify-start font-normal" onClick={() => setPicking(true)}>
                    <Building2 className="text-muted-foreground" />
                    All businesses
                </Button>
            )}

            <div className="flex items-center gap-2">
                <Input
                    type="date"
                    value={filters.from ?? ''}
                    max={filters.to ?? undefined}
                    onChange={(event) => update({ from: event.target.value || undefined, page: 1 })}
                    aria-label="Issued from"
                    className="h-9 w-full sm:w-38"
                />
                <span className="text-muted-foreground text-sm">to</span>
                <Input
                    type="date"
                    value={filters.to ?? ''}
                    min={filters.from ?? undefined}
                    onChange={(event) => update({ to: event.target.value || undefined, page: 1 })}
                    aria-label="Issued to"
                    className="h-9 w-full sm:w-38"
                />
            </div>

            {active && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-9 self-start sm:self-auto"
                    onClick={() => update({ status: undefined, company: undefined, from: undefined, to: undefined, page: 1 })}
                >
                    <X />
                    Clear filters
                </Button>
            )}

            <BusinessPicker
                open={picking}
                onOpenChange={setPicking}
                title="Filter by business"
                description="Show only the invoices of one customer."
                onPick={(id) => {
                    setPicking(false);
                    update({ company: id, page: 1 });
                }}
            />
        </>
    );
}

export default InvoiceFilters;
