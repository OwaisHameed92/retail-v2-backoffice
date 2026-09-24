import { InitialsAvatar } from '@/components/shared/entity-cell';
import { statusDotClasses, statusTone } from '@/components/shared/status-badge';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { FollowUp } from './follow-up';
import { formatDate, leadStatusTones } from './format';
import { shopsAndTills } from './lead-columns';
import { type BoardColumn, type LeadRow } from './types';

const number = new Intl.NumberFormat('en-GB');

function BoardCard({ lead }: { lead: LeadRow }) {
    return (
        <li>
            <Link
                href={route('admin.leads.show', lead.id)}
                className="bg-card hover:border-border-strong focus-visible:ring-ring/40 shadow-card block rounded-lg border p-3 transition-colors outline-none focus-visible:ring-2"
            >
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <p className="text-foreground truncate text-sm font-medium">{lead.businessName}</p>
                        <p className="text-muted-foreground truncate text-xs">{[lead.contactName, lead.town].filter(Boolean).join(' · ')}</p>
                    </div>
                    {lead.assignedAdmin ? (
                        <span title={`Assigned to ${lead.assignedAdmin.name}`}>
                            <InitialsAvatar name={lead.assignedAdmin.name} size="sm" />
                            <span className="sr-only">Assigned to {lead.assignedAdmin.name}</span>
                        </span>
                    ) : null}
                </div>
                <div className="text-muted-foreground mt-2.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs">
                    <span className="tabular-nums">{shopsAndTills(lead)}</span>
                    {lead.followUpAt ? <FollowUp at={lead.followUpAt} /> : <span className="tabular-nums">{formatDate(lead.createdAt)}</span>}
                </div>
            </Link>
        </li>
    );
}

interface LeadBoardProps {
    columns: BoardColumn[] | null;
    loading?: boolean;
    /** Opens the list filtered to one status ("View all 48"). */
    listHref: (status: string) => string;
}

/**
 * Leads grouped by status: new → contacted → converted → rejected. Open columns show the next follow-up first.
 * Columns scroll sideways on narrow screens. Status changes happen on the lead page (they need a reason or a
 * confirmation), so cards are links, not drag handles.
 */
export function LeadBoard({ columns, loading = false, listHref }: LeadBoardProps) {
    if (!columns) {
        return null;
    }

    return (
        <div className="-mx-4 overflow-x-auto px-4 pb-2 md:mx-0 md:px-0" aria-busy={loading}>
            <div className="grid min-w-[56rem] grid-cols-4 gap-4">
                {columns.map((column) => {
                    const tone = statusTone(column.status, leadStatusTones);

                    return (
                        <section
                            key={column.status}
                            aria-labelledby={`board-${column.status}`}
                            className="bg-subtle flex min-h-48 flex-col rounded-xl border p-2"
                        >
                            <header className="flex items-center justify-between px-2 pt-1 pb-2.5">
                                <h2 id={`board-${column.status}`} className="flex items-center gap-2 text-sm font-semibold">
                                    <span className={cn('size-2 rounded-full', statusDotClasses[tone])} aria-hidden />
                                    {column.label}
                                </h2>
                                <span className="text-muted-foreground text-xs font-medium tabular-nums">{number.format(column.total)}</span>
                            </header>

                            {loading ? (
                                <div className="grid gap-2">
                                    {Array.from({ length: 3 }).map((_, index) => (
                                        <Skeleton key={index} className="h-[74px] rounded-lg" />
                                    ))}
                                </div>
                            ) : column.leads.length === 0 ? (
                                <p className="text-muted-foreground px-2 py-6 text-center text-sm">No leads</p>
                            ) : (
                                <ul className="grid gap-2">
                                    {column.leads.map((lead) => (
                                        <BoardCard key={lead.id} lead={lead} />
                                    ))}
                                </ul>
                            )}

                            {!loading && column.total > column.leads.length && (
                                <Link
                                    href={listHref(column.status)}
                                    className="text-primary hover:bg-card mt-2 inline-flex items-center justify-center gap-1 rounded-md px-2 py-1.5 text-sm font-medium"
                                >
                                    View all {number.format(column.total)}
                                    <ArrowRight className="size-3.5" aria-hidden />
                                </Link>
                            )}
                        </section>
                    );
                })}
            </div>
        </div>
    );
}
