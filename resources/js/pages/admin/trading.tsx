import { DashboardTabs } from '@/components/admin/dashboard/dashboard-tabs';
import { dayRange, number, share } from '@/components/admin/trading/format';
import { Freshness } from '@/components/admin/trading/freshness';
import { LeadersCard, salesDetail, type LeaderItem } from '@/components/admin/trading/leaders-card';
import { HourlyPatternCard, SalesTrendCard } from '@/components/admin/trading/sales-charts';
import { TenderMixCard, VatCard } from '@/components/admin/trading/tender-vat-cards';
import { queryOf, TradingFiltersBar, type TradingQuery } from '@/components/admin/trading/trading-filters';
import { TradingKpis } from '@/components/admin/trading/trading-kpis';
import { TradingSkeleton } from '@/components/admin/trading/trading-skeleton';
import { type TradingData, type TradingPageProps } from '@/components/admin/trading/types';
import { PageHeader, type PageCrumb } from '@/components/shared/page-header';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';
import { Deferred, Head, router } from '@inertiajs/react';
import { Building2, Monitor, Package, Store } from 'lucide-react';
import { useState } from 'react';

const RELOAD = ['filters', 'context', 'trading'];

function TradingBody({ data, props, go }: { data: TradingData; props: TradingPageProps; go: (query: TradingQuery) => void }) {
    const total = data.kpis.totals.net;
    const drillBusiness = (id: string) => go(queryOf(props.filters, { company: id, branch: null }));
    const drillShop = (company: string, branch: string) => go(queryOf(props.filters, { company, branch }));
    const { businesses, shops, tills, products } = data.leaders;

    return (
        <div className="flex flex-col gap-4">
            <TradingKpis data={data} />

            <div className="grid gap-4 xl:grid-cols-12">
                <div className="min-w-0 xl:col-span-8">
                    <SalesTrendCard data={data} />
                </div>
                <div className="min-w-0 xl:col-span-4">
                    <TenderMixCard tenders={data.tenders} />
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-12">
                {data.daily !== null && (
                    <div className="min-w-0 xl:col-span-7">
                        <HourlyPatternCard data={data} />
                    </div>
                )}
                <div className={cn('min-w-0', data.daily !== null ? 'xl:col-span-5' : 'xl:col-span-12')}>
                    <VatCard rows={data.vat} />
                </div>
            </div>

            <div className="grid items-start gap-4 lg:grid-cols-2">
                {businesses && (
                    <LeadersCard
                        title="Top businesses"
                        description="By net sales. Select one to see its shops."
                        icon={Building2}
                        total={total}
                        emptyTitle="No business traded"
                        emptyBody="Businesses appear here once their tills push sales."
                        items={businesses.map<LeaderItem>((b) => ({
                            id: b.id,
                            name: b.label,
                            subline: `${number(b.children)} ${b.children === 1 ? 'shop' : 'shops'} · ${share(b.net, total).toFixed(1)}% of sales`,
                            value: b.net,
                            detail: salesDetail(b.transactions, b.averageBasketExVat),
                            onSelect: () => drillBusiness(b.id),
                        }))}
                    />
                )}
                {shops && (
                    <LeadersCard
                        title={data.level === 'all' ? 'Top shops' : 'Shops'}
                        description={data.level === 'all' ? 'Across every business, by net sales.' : 'Every shop of the business, by net sales.'}
                        icon={Store}
                        total={total}
                        avatar="circle"
                        emptyTitle="No shop traded"
                        emptyBody="Shops appear here once their tills push sales."
                        items={shops.map<LeaderItem>((s) => ({
                            id: s.id,
                            name: s.label,
                            subline: data.level === 'all' ? s.parentLabel : `${number(s.children)} ${s.children === 1 ? 'till' : 'tills'}`,
                            value: s.net,
                            detail: salesDetail(s.transactions, s.averageBasketExVat),
                            onSelect: () => drillShop(s.parentId, s.id),
                        }))}
                    />
                )}
                {tills && (
                    <LeadersCard
                        title="Tills"
                        description="Every till of the shop, by net sales."
                        icon={Monitor}
                        total={total}
                        avatar="none"
                        emptyTitle="No till traded"
                        emptyBody="Tills appear here once they push sales."
                        items={tills.map<LeaderItem>((t) => ({
                            id: t.id,
                            name: t.label,
                            subline: `${number(t.refundCount)} ${t.refundCount === 1 ? 'refund' : 'refunds'}`,
                            value: t.net,
                            detail: salesDetail(t.transactions, t.averageBasketExVat),
                        }))}
                    />
                )}
                {products && (
                    <LeadersCard
                        title="Top products"
                        description="By net sales, refunds taken off."
                        icon={Package}
                        total={total}
                        avatar="none"
                        emptyTitle="No products sold"
                        emptyBody="The best sellers of the chosen shops show here."
                        items={products.map<LeaderItem>((p) => ({
                            id: p.productId,
                            name: p.name,
                            subline: `${Number(p.qty).toLocaleString('en-GB', { maximumFractionDigits: 3 })} sold${p.department === 'Unassigned' ? '' : ` · ${p.department}`}`,
                            value: p.net,
                        }))}
                    />
                )}
            </div>
        </div>
    );
}

export default function AdminTrading(props: TradingPageProps) {
    const { filters, context, periods, compares, trading } = props;
    const [loading, setLoading] = useState(false);

    const go = (query: TradingQuery) =>
        router.get(route('admin.trading'), query, {
            only: RELOAD,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    const where = context.branch ? `${context.branch.name}, ${context.company?.name}` : (context.company?.name ?? 'every business');
    const breadcrumbs: PageCrumb[] | undefined = context.company
        ? [
              { title: 'All businesses', href: route('admin.trading', queryOf(filters, { company: null, branch: null })) },
              ...(context.branch
                  ? [{ title: context.company.name, href: route('admin.trading', queryOf(filters, { branch: null })) }, { title: context.branch.name }]
                  : [{ title: context.company.name }]),
          ]
        : undefined;

    return (
        <AdminLayout>
            <Head title="Trading" />

            <PageHeader
                title="Trading"
                breadcrumbs={breadcrumbs}
                description={
                    <>
                        Shop sales of {where}, {dayRange(filters.from, filters.to)}.
                        {trading && trading.level === 'all' && (
                            <>
                                {' '}
                                {number(trading.activity.companies)} {trading.activity.companies === 1 ? 'business' : 'businesses'} and{' '}
                                {number(trading.activity.branches)} {trading.activity.branches === 1 ? 'shop' : 'shops'} traded.
                            </>
                        )}
                    </>
                }
                actions={<Freshness data={trading} loading={loading} />}
                tabs={<DashboardTabs active="trading" />}
            />

            <TradingFiltersBar filters={filters} context={context} periods={periods} compares={compares} onChange={go} />

            <div className={cn('transition-opacity', loading && 'pointer-events-none opacity-60')} aria-busy={loading}>
                <Deferred data="trading" fallback={<TradingSkeleton />}>
                    {trading ? <TradingBody data={trading} props={props} go={go} /> : <TradingSkeleton />}
                </Deferred>
            </div>
        </AdminLayout>
    );
}
