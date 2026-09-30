import { money, number, share } from '@/components/admin/trading/format';
import { EmptyState } from '@/components/shared/empty-state';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { SectionCard } from '@/components/shared/section-card';
import { cn } from '@/lib/utils';
import { ChevronRight, type LucideIcon } from 'lucide-react';

export interface LeaderItem {
    id: string;
    name: string;
    /** "Kirkgate Convenience", "3 shops", "412 items". */
    subline?: string;
    /** Decimal string (net sales). */
    value: string;
    /** Second figure under the value: "412 txns · £8.20 avg". */
    detail?: string;
    /** Drill into the item (a business or a shop). */
    onSelect?: () => void;
}

interface LeadersCardProps {
    title: string;
    description: string;
    icon: LucideIcon;
    items: LeaderItem[];
    /** The whole to show each item's share of (total net sales). */
    total: string;
    emptyTitle: string;
    emptyBody: string;
    avatar?: 'square' | 'circle' | 'none';
}

/**
 * A ranked list (top businesses, top shops, tills, top products): rank, name with a subline, a share bar of the
 * total, the value and a detail line. Items with `onSelect` drill down. One layout for desktop and phone.
 */
export function LeadersCard({ title, description, icon, items, total, emptyTitle, emptyBody, avatar = 'square' }: LeadersCardProps) {
    return (
        <SectionCard title={title} description={description} flush className="min-w-0">
            {items.length === 0 ? (
                <EmptyState icon={icon} title={emptyTitle} body={emptyBody} size="sm" />
            ) : (
                <ol className="divide-y">
                    {items.map((item, index) => {
                        const pct = share(item.value, total);
                        const body = (
                            <>
                                <span className="text-muted-foreground w-5 shrink-0 text-right text-xs tabular-nums">{index + 1}</span>
                                {avatar !== "none" && <InitialsAvatar name={item.name} shape={avatar} size="sm" className="hidden sm:inline-flex" />}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">{item.name}</span>
                                    {item.subline && <span className="text-muted-foreground block truncate text-xs">{item.subline}</span>}
                                    {item.detail && <span className="text-muted-foreground block truncate text-xs tabular-nums sm:hidden">{item.detail}</span>}
                                    <span className="bg-muted mt-1.5 block h-1.5 w-full max-w-56 overflow-hidden rounded-full" aria-hidden>
                                        <span className="bg-primary block h-full rounded-full" style={{ width: `${Math.max(pct, 1.5)}%` }} />
                                    </span>
                                </span>
                                <span className="shrink-0 text-right">
                                    <span className="block text-sm font-semibold tabular-nums">{money(item.value)}</span>
                                    <span className={cn('text-muted-foreground block text-xs tabular-nums', item.detail && 'hidden sm:block')}>
                                        {item.detail ?? `${pct.toFixed(1)}% of sales`}
                                    </span>
                                </span>
                                {item.onSelect && <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />}
                            </>
                        );

                        return (
                            <li key={item.id}>
                                {item.onSelect ? (
                                    <button
                                        type="button"
                                        onClick={item.onSelect}
                                        className={cn(
                                            'hover:bg-muted/40 focus-visible:bg-muted/60 flex w-full items-center gap-3 px-5 py-3 text-left transition-colors outline-none sm:px-6',
                                        )}
                                        aria-label={`${item.name}: ${money(item.value)}. Show its trading`}
                                    >
                                        {body}
                                    </button>
                                ) : (
                                    <div className="flex items-center gap-3 px-5 py-3 sm:px-6">{body}</div>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}
        </SectionCard>
    );
}

/** "412 txns · £8.20 avg". */
export function salesDetail(transactions: number, average: string | null): string {
    return `${number(transactions)} ${transactions === 1 ? 'txn' : 'txns'}${average === null ? '' : ` · ${money(average)} avg`}`;
}
