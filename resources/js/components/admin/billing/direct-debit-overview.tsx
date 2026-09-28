import { formatDay, plural } from '@/components/admin/billing/format';
import { type BillingOverviewProps } from '@/components/admin/billing/types';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Link } from '@inertiajs/react';
import { CalendarClock, Landmark, ShieldCheck, TriangleAlert, UserX } from 'lucide-react';

/** Direct Debit figures on the Billing overview (module 1.12): mandates, upcoming collections, failures. */
export function DirectDebitOverview({ data }: { data: BillingOverviewProps['directDebit'] }) {
    if (!data.enabled && data.activeMandates === 0 && data.upcoming.count === 0 && data.withoutMandate === 0) {
        return null;
    }

    return (
        <SectionCard
            title={
                <span className="inline-flex items-center gap-2">
                    Direct Debit
                    {data.enabled && data.environment === 'sandbox' && <StatusPill tone="violet">Sandbox</StatusPill>}
                    {!data.enabled && <StatusPill tone="warning">GoCardless not connected</StatusPill>}
                </span>
            }
            description="Collected by GoCardless. Each collection has its own invoice; a confirmed payment renews the tills."
        >
            <div className="grid gap-5">
                <StatGrid>
                    <StatCard label="Active mandates" value={data.activeMandates} hint="Customers paying by Direct Debit" icon={ShieldCheck} tone="success" />
                    <StatCard
                        label="Without a mandate"
                        value={data.withoutMandate}
                        hint={data.withoutMandate === 0 ? 'Everyone is set up' : 'Direct Debit customers to chase'}
                        icon={UserX}
                        tone={data.withoutMandate > 0 ? 'warning' : 'neutral'}
                    />
                    <StatCard
                        label="Upcoming collections"
                        value={data.upcoming.amount}
                        hint={data.upcoming.count === 0 ? 'Nothing scheduled' : plural(data.upcoming.count, 'payment')}
                        icon={CalendarClock}
                        tone="primary"
                    />
                    <StatCard
                        label="Failed (30 days)"
                        value={data.failed.amount}
                        hint={data.failed.count === 0 ? 'No failed payments' : plural(data.failed.count, 'payment')}
                        icon={TriangleAlert}
                        tone={data.failed.count > 0 ? 'danger' : 'neutral'}
                    />
                </StatGrid>

                {data.next.length === 0 ? (
                    <EmptyState icon={Landmark} size="sm" title="No collections scheduled" body="GoCardless payments appear here once they are created." />
                ) : (
                    <ul className="-mx-5 divide-y border-t sm:-mx-6">
                        {data.next.map((payment) => (
                            <li key={payment.id} className="flex items-center gap-4 px-5 py-3 text-sm sm:px-6">
                                <div className="min-w-0 flex-1 leading-tight">
                                    <Link href={route('admin.tenants.show', { company: payment.companyId, tab: 'billing' })} className="truncate font-medium hover:underline">
                                        {payment.companyName}
                                    </Link>
                                    <div className="text-muted-foreground mt-0.5 truncate text-xs">
                                        {payment.statusLabel}
                                        {payment.invoiceId && (
                                            <>
                                                {' · '}
                                                <Link href={route('admin.billing.invoices.show', payment.invoiceId)} className="text-primary font-mono hover:underline">
                                                    {payment.invoiceNumber ?? 'Draft'}
                                                </Link>
                                            </>
                                        )}
                                    </div>
                                </div>
                                <span className="text-muted-foreground hidden sm:inline">{formatDay(payment.chargeDate)}</span>
                                <span className="w-24 shrink-0 text-right font-semibold tabular-nums">{payment.amount}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </SectionCard>
    );
}

export default DirectDebitOverview;
