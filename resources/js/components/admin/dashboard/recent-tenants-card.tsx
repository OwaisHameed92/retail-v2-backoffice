import { type RecentTenant } from '@/components/admin/dashboard/types';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { MobileCardList } from '@/components/shared/mobile-card-list';
import { StatusBadge } from '@/components/shared/status-badge';
import { Card } from '@/components/ui/card';
import { relativeTime } from '@/lib/relative-time';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, Building2, Lock } from 'lucide-react';

function Mrr({ value }: { value: string | null }) {
    if (value !== null) {
        return <>{value}</>;
    }

    return (
        <span className="text-muted-foreground inline-flex items-center gap-1" title="Needs billing access">
            <Lock className="size-3" aria-hidden />—<span className="sr-only">Hidden: needs billing access</span>
        </span>
    );
}

function Activity({ at }: { at: string | null }) {
    return at ? <time dateTime={at}>{relativeTime(at)}</time> : <span className="text-muted-foreground">No activity yet</span>;
}

/** "Recent tenants": the 5 most recently active businesses with plan, tills, MRR, last activity and status. */
export function RecentTenantsCard({ tenants, viewAllHref }: { tenants: RecentTenant[]; viewAllHref?: string }) {
    return (
        <Card className="flex flex-col overflow-clip">
            <div className="flex items-center gap-3 px-5 pt-5 pb-3">
                <Building2 className="text-primary size-5" aria-hidden />
                <h2 className="text-foreground flex-1 text-base font-semibold tracking-tight">Recent tenants</h2>
                {viewAllHref && (
                    <Link href={viewAllHref} className="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline">
                        View all
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                )}
            </div>
            {tenants.length === 0 ? (
                <div className="border-t">
                    <EmptyState icon={Building2} title="No tenants yet" body="New businesses and their plan, tills and MRR will list here." size="sm" />
                </div>
            ) : (
                <>
                    <div className="hidden border-t md:block">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left text-xs font-medium">
                                    <th className="px-5 py-2.5 font-medium">Business</th>
                                    <th className="px-3 py-2.5 font-medium">Plan</th>
                                    <th className="px-3 py-2.5 text-right font-medium">Tills</th>
                                    <th className="px-3 py-2.5 text-right font-medium">MRR</th>
                                    <th className="px-3 py-2.5 font-medium">Last activity</th>
                                    <th className="px-5 py-2.5 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {tenants.map((tenant) => (
                                    <tr key={tenant.id} className="hover:bg-muted/40 cursor-pointer transition-colors" onClick={() => router.visit(tenant.href)}>
                                        <td className="max-w-56 px-5 py-3">
                                            <EntityCell name={tenant.name} shape="square" href={tenant.href} />
                                        </td>
                                        <td className="text-muted-foreground px-3 py-3">{tenant.plan ?? 'Not set'}</td>
                                        <td className="px-3 py-3 text-right tabular-nums">{tenant.tills}</td>
                                        <td className="px-3 py-3 text-right tabular-nums">
                                            <Mrr value={tenant.mrr} />
                                        </td>
                                        <td className="text-muted-foreground px-3 py-3 whitespace-nowrap tabular-nums">
                                            <Activity at={tenant.lastActivityAt} />
                                        </td>
                                        <td className="px-5 py-3">
                                            <StatusBadge status={tenant.status} label={tenant.statusLabel} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <MobileCardList
                        className="border-t md:hidden"
                        items={tenants}
                        getKey={(tenant) => tenant.id}
                        onItemClick={(tenant) => router.visit(tenant.href)}
                        render={(tenant) => ({
                            title: <EntityCell name={tenant.name} subline={tenant.plan ?? undefined} shape="square" href={tenant.href} />,
                            aside: <StatusBadge status={tenant.status} label={tenant.statusLabel} />,
                            fields: [
                                { label: 'Tills', value: tenant.tills },
                                { label: 'MRR', value: <Mrr value={tenant.mrr} /> },
                                { label: 'Last activity', value: <Activity at={tenant.lastActivityAt} /> },
                            ],
                        })}
                    />
                </>
            )}
        </Card>
    );
}
