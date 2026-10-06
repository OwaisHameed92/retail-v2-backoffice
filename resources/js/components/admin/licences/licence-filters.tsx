import { licenceStatusLabels } from '@/components/admin/licences/format';
import { type LicenceIndexProps } from '@/components/admin/licences/types';
import { useTableQuery } from '@/components/shared/data-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatNumber } from '@/lib/country';
import { sendJson } from '@/lib/http';
import { Building2, LoaderCircle, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const ONLY = ['licences', 'filters', 'counts'];

interface TenantHit {
    id: string;
    name: string;
    detail: string | null;
}

/** Choose one business to filter by, searching tenants as you type. */
export function BusinessPicker({
    open,
    onOpenChange,
    onPick,
    title = 'Filter by business',
    description = 'Show only the licences of one customer.',
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPick: (id: string, name: string) => void;
    title?: string;
    description?: string;
}) {
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<TenantHit[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!open || query.trim().length < 2) {
            setHits([]);

            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            try {
                const result = await sendJson<{ tenants: TenantHit[] }>(
                    'GET',
                    `${route('admin.search')}?q=${encodeURIComponent(query.trim())}`,
                    undefined,
                    controller.signal,
                );
                setHits(result.data?.tenants ?? []);
            } catch {
                // Aborted by the next keystroke.
            } finally {
                setLoading(false);
            }
        }, 200);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, open]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" aria-hidden />
                    <Input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Business name or owner email"
                        className="pl-8"
                        aria-label="Search businesses"
                    />
                    {loading && (
                        <LoaderCircle className="text-muted-foreground absolute top-1/2 right-2.5 size-4 -translate-y-1/2 animate-spin" aria-hidden />
                    )}
                </div>
                <ul className="grid max-h-72 gap-1 overflow-y-auto" aria-label="Businesses">
                    {hits.map((hit) => (
                        <li key={hit.id}>
                            <button
                                type="button"
                                onClick={() => onPick(hit.id, hit.name)}
                                className="hover:bg-muted focus-visible:bg-muted focus-visible:ring-ring flex w-full items-center gap-3 rounded-md px-2 py-2 text-left focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <Building2 className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-medium">{hit.name}</span>
                                    {hit.detail && <span className="text-muted-foreground block truncate text-xs">{hit.detail}</span>}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
                {query.trim().length >= 2 && !loading && hits.length === 0 && (
                    <p className="text-muted-foreground text-sm">No business matches “{query.trim()}”.</p>
                )}
                {query.trim().length < 2 && <p className="text-muted-foreground text-sm">Type at least 2 characters.</p>}
            </DialogContent>
        </Dialog>
    );
}

/** Status (with counts), plan and business filters for the licence list. All live in the URL. */
export function LicenceFilters({ filters, statuses, counts, plans }: Pick<LicenceIndexProps, 'filters' | 'statuses' | 'counts' | 'plans'>) {
    const { update } = useTableQuery({ only: ONLY });
    const [picking, setPicking] = useState(false);

    return (
        <>
            <Select value={filters.status ?? 'all'} onValueChange={(next) => update({ status: next === 'all' ? undefined : next, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-44" aria-label="Filter by status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    {statuses.map((status) => (
                        <SelectItem key={status.value} value={status.value}>
                            {licenceStatusLabels[status.value]}
                            <span className="text-muted-foreground ml-1 tabular-nums">({formatNumber(counts[status.value] ?? 0)})</span>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select value={filters.plan ?? 'all'} onValueChange={(next) => update({ plan: next === 'all' ? undefined : next, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-40" aria-label="Filter by plan">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All plans</SelectItem>
                    {plans.map((plan) => (
                        <SelectItem key={plan.value} value={plan.value}>
                            {plan.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {filters.company ? (
                <span className="bg-accent inline-flex h-9 max-w-full items-center gap-1 rounded-md border pr-1 pl-3 text-sm">
                    <Building2 className="text-muted-foreground size-4 shrink-0" aria-hidden />
                    <span className="truncate">{filters.company.name}</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label={`Stop filtering by ${filters.company.name}`}
                        onClick={() => update({ company: undefined, page: 1 })}
                    >
                        <X className="size-4" />
                    </Button>
                </span>
            ) : (
                <Button variant="outline" className="h-9 justify-start font-normal" onClick={() => setPicking(true)}>
                    <Building2 className="text-muted-foreground" />
                    All businesses
                </Button>
            )}

            <BusinessPicker
                open={picking}
                onOpenChange={setPicking}
                onPick={(id) => {
                    setPicking(false);
                    update({ company: id, page: 1 });
                }}
            />
        </>
    );
}
