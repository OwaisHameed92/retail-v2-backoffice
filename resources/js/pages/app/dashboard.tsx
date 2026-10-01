import { BusinessBody, NoSalesYet } from '@/components/app/dashboard/business-body';
import { BusinessFiltersBar, type BusinessQuery } from '@/components/app/dashboard/business-filters';
import { type BusinessDashboardProps } from '@/components/app/dashboard/types';
import { YesterdayCard, YesterdaySkeleton } from '@/components/app/dashboard/yesterday-card';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { dayRange } from '@/components/shared/trading/format';
import { Freshness } from '@/components/shared/trading/freshness';
import { TradingSkeleton } from '@/components/shared/trading/trading-skeleton';
import { WelcomeBanner } from '@/components/shared/welcome-banner';
import { ShopsStatusCard } from '@/components/till-health/shops-status-card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/app' }];

const RELOAD = ['filters', 'context', 'status', 'sales'];

/**
 * The tenant dashboard: how the business (or one shop, or one till) is trading (module 3.3, `reports.view`), and
 * its shops and tills (module 2.7). Figures load as a deferred prop behind a skeleton.
 */
export default function Dashboard(props: BusinessDashboardProps) {
    const { status, canSales, filters, context, periods, compares, sales, yesterday } = props;
    const { auth, company } = usePage<SharedData>().props;
    const [loading, setLoading] = useState(false);

    const go = (query: BusinessQuery) =>
        router.get(route('app.dashboard'), query, {
            only: RELOAD,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    const switchShop = (id: string) =>
        router.post(route('app.branch.switch'), { branch_id: id }, { preserveScroll: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) });

    const where = context.till ? `till ${context.till.label} at ${context.branch?.name}` : (context.branch?.name ?? `all shops of ${company?.name ?? 'your business'}`);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <WelcomeBanner
                name={auth.user.name.split(' ')[0]}
                subtitle={canSales ? `How ${where} traded, ${dayRange(filters.from, filters.to)}.` : `Shops and tills of ${company?.name ?? 'your business'}.`}
                actions={canSales ? <Freshness info={sales?.freshness} generatedAt={sales?.generatedAt} loading={loading} /> : undefined}
            />

            {canSales ? (
                <>
                    <Deferred data="yesterday" fallback={<YesterdaySkeleton />}>
                        <>{yesterday && <YesterdayCard data={yesterday} />}</>
                    </Deferred>

                    <BusinessFiltersBar filters={filters} context={context} periods={periods} compares={compares} onChange={go} onLoading={setLoading} />

                    <div className={cn('transition-opacity', loading && 'pointer-events-none opacity-60')} aria-busy={loading}>
                        <Deferred data="sales" fallback={<TradingSkeleton />}>
                            {!sales ? (
                                <TradingSkeleton />
                            ) : sales.freshness.hasData ? (
                                <BusinessBody data={sales} status={status} onShop={switchShop} />
                            ) : (
                                <NoSalesYet shop={context.branch?.name ?? null} />
                            )}
                        </Deferred>
                    </div>
                </>
            ) : (
                <SectionCard>
                    <EmptyState
                        icon={LockKeyhole}
                        title="Sales figures are not part of your role"
                        body="Owners, managers and accountants see sales, takings and VAT here. Ask the business owner if you need them."
                        size="sm"
                    />
                </SectionCard>
            )}

            {status && <ShopsStatusCard status={status} />}
        </AppLayout>
    );
}
