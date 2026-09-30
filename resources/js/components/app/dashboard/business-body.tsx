import { OperationsTiles } from '@/components/app/dashboard/operations-tiles';
import { DepartmentsCard, ShopsOrTillsCard, StaffCard, TopProductsCard } from '@/components/app/dashboard/sales-lists';
import { type BusinessData } from '@/components/app/dashboard/types';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { HourlyPatternCard, SalesTrendCard } from '@/components/shared/trading/sales-charts';
import { TenderMixCard, VatCard } from '@/components/shared/trading/tender-vat-cards';
import { TradingKpis } from '@/components/shared/trading/trading-kpis';
import { type ShopsStatus } from '@/components/till-health/types';
import { cn } from '@/lib/utils';
import { RefreshCw } from 'lucide-react';

/** A business (or shop) with no figures on any day yet: the till must sync first. */
export function NoSalesYet({ shop }: { shop: string | null }) {
    return (
        <SectionCard>
            <EmptyState
                icon={RefreshCw}
                title={shop ? `No sales from ${shop} yet` : 'No sales yet'}
                body="Sales appear here once a till is linked to Switch & Save cloud sync and has synced. Each sale shows within a minute or two of reaching the portal; sales made while a till was offline arrive when it reconnects."
            />
        </SectionCard>
    );
}

/** Every Business-panel tile and chart of DASHBOARD.md §2 for the chosen shops and days. */
export function BusinessBody({ data, status, onShop }: { data: BusinessData; status: ShopsStatus | null; onShop: (id: string) => void }) {
    return (
        <div className="flex flex-col gap-4">
            <TradingKpis data={data} />
            <OperationsTiles operations={data.operations} status={status} />

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
                <ShopsOrTillsCard data={data} onShop={onShop} />
                <TopProductsCard data={data} />
                <DepartmentsCard data={data} />
                <StaffCard data={data} />
            </div>
        </div>
    );
}
