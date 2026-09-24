import { formatDateTimeShort } from '@/components/admin/tenants/format';
import { type TenantActivityRow } from '@/components/admin/tenants/types';
import { DataTablePagination, useTableQuery, type Paginated } from '@/components/shared/data-table';
import { SectionCard } from '@/components/shared/section-card';
import { Timeline, type TimelineTone } from '@/components/shared/timeline';
import {
    Ban,
    Building2,
    CirclePause,
    CirclePlay,
    History,
    KeyRound,
    LogIn,
    MonitorSmartphone,
    Pencil,
    Plus,
    Store,
    UserRound,
    type LucideIcon,
} from 'lucide-react';

/** Icon and tone for an audit action such as "licence.suspended" or "tenant.created". */
function look(action: string): { icon: LucideIcon; tone: TimelineTone } {
    const [subject, verb = ''] = action.split('.');
    if (/suspend|deactivat|revok/.test(verb)) return { icon: CirclePause, tone: 'danger' };
    if (/cancel/.test(verb)) return { icon: Ban, tone: 'danger' };
    if (/reactivat|unsuspend|activat|restor|renew/.test(verb)) return { icon: CirclePlay, tone: 'success' };
    if (/impersonat/.test(action)) return { icon: LogIn, tone: 'warning' };
    if (/created|issued|added/.test(verb)) return { icon: Plus, tone: 'primary' };
    const bySubject: Record<string, LucideIcon> = {
        tenant: Building2,
        company: Building2,
        branch: Store,
        register: MonitorSmartphone,
        licence: KeyRound,
        user: UserRound,
        member: UserRound,
    };

    return { icon: bySubject[subject] ?? (verb.includes('update') ? Pencil : History), tone: 'neutral' };
}

/** Audit log entries for this company, newest first, as a timeline with pagination. */
export function ActivityPanel({ activity }: { activity: Paginated<TenantActivityRow> }) {
    const { update, loading } = useTableQuery({ only: ['activity'] });

    return (
        <SectionCard
            title="Activity"
            description="Everything done to this account, by our staff and by the customer."
            footer={
                activity.meta.total > activity.meta.perPage ? (
                    <DataTablePagination
                        className="w-full"
                        meta={activity.meta}
                        disabled={loading}
                        onPageChange={(page) => update({ page })}
                        onPerPageChange={(perPage) => update({ perPage, page: 1 })}
                    />
                ) : undefined
            }
        >
            <div aria-busy={loading} className={loading ? 'opacity-60 transition-opacity' : undefined}>
                <Timeline
                    emptyBody="Changes to this account are recorded here."
                    items={activity.data.map((row) => ({
                        id: row.id,
                        ...look(row.action),
                        title: (
                            <>
                                {row.description} <span className="text-muted-foreground">· {row.actorName}</span>
                            </>
                        ),
                        time: formatDateTimeShort(row.createdAt),
                    }))}
                />
            </div>
        </SectionCard>
    );
}
