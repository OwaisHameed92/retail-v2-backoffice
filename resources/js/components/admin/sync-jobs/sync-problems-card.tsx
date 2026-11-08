import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/country';
import { Link } from '@inertiajs/react';
import { Activity, CloudUpload, RefreshCcwDot } from 'lucide-react';
import { ProblemKindPill, When } from './format';
import { ListTable, type ListColumn } from './list-table';
import { type SyncProblem } from './types';

const columns: ListColumn<SyncProblem>[] = [
    {
        key: 'business',
        header: 'Business',
        className: 'max-w-56',
        cell: (problem) => (
            <div className="min-w-0">
                <Link href={route('admin.tenants.show', problem.business.id)} className="text-primary font-medium break-words hover:underline">
                    {problem.business.name}
                </Link>
                <div className="text-muted-foreground mt-0.5 text-xs break-words">
                    {[problem.shop, problem.till].filter(Boolean).join(' · ') || 'Shop not known'}
                </div>
            </div>
        ),
    },
    { key: 'kind', header: 'Problem', mobile: 'aside', cell: (problem) => <ProblemKindPill kind={problem.kind} /> },
    {
        key: 'entity',
        header: 'Record type',
        cell: (problem) =>
            problem.entity ? <span className="font-mono text-[13px]">{problem.entity}</span> : <span className="text-muted-foreground">—</span>,
    },
    { key: 'count', header: 'Rows', align: 'right', cell: (problem) => formatNumber(problem.count) },
    {
        key: 'detail',
        header: 'Detail',
        className: 'max-w-80',
        cell: (problem) => <span className="text-muted-foreground text-xs break-words">{problem.detail ?? '—'}</span>,
    },
    { key: 'last', header: 'Last seen', cell: (problem) => <When at={problem.lastSeenAt} /> },
    {
        key: 'links',
        header: 'Open',
        cell: (problem) => (
            <div className="flex flex-wrap gap-1">
                <Button variant="ghost" size="sm" asChild>
                    <Link
                        href={route('admin.till-health.index', { company: problem.business.id })}
                        aria-label={`Till health of ${problem.business.name}`}
                    >
                        <Activity />
                        Till health
                    </Link>
                </Button>
            </div>
        ),
    },
];

/** Refused pushes, unknown entities and open conflicts, by business, shop, till and record type. Read only. */
export function SyncProblemsCard({ problems }: { problems: { rows: SyncProblem[]; total: number; windowDays: number } }) {
    return (
        <SectionCard
            id="sync-problems"
            flush
            className="scroll-mt-24"
            title="Sync problems"
            description={`Refused pushes and unknown record types from the last ${problems.windowDays} days, and every open conflict. The business decides conflicts in its portal.`}
            actions={
                <Button variant="outline" size="sm" asChild>
                    <Link href={route('admin.cloud-link.index')}>
                        <CloudUpload />
                        Cloud link
                    </Link>
                </Button>
            }
            footer={
                problems.total > problems.rows.length ? (
                    <span className="text-muted-foreground">
                        Showing the {formatNumber(problems.rows.length)} most recent of {formatNumber(problems.total)} groups.
                    </span>
                ) : undefined
            }
        >
            {problems.rows.length === 0 ? (
                <EmptyState
                    icon={RefreshCcwDot}
                    tone="success"
                    size="sm"
                    title="No sync problems"
                    body="Every till push was accepted, every record type is known and no conflict is waiting."
                />
            ) : (
                <ListTable label="Sync problems" columns={columns} rows={problems.rows} getKey={(problem) => problem.key} />
            )}
        </SectionCard>
    );
}
