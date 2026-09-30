import { AccountsFilters, AccountsPageLayout, Amount, RefundFixNotice } from '@/components/app/accounts/accounts-page';
import { StatementSection, StatementTotal } from '@/components/app/accounts/statement-section';
import { type ProfitAndLossProps } from '@/components/app/accounts/types';
import { useTableQuery } from '@/components/shared/data-table';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { money } from '@/components/shared/trading/format';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { BarChart3, Info, PoundSterling, TrendingUp } from 'lucide-react';

export default function AccountsProfitAndLoss({ income, costOfSales, overheads, totals, salesCheck, refundFix, filters, options }: ProfitAndLossProps) {
    const { update } = useTableQuery();
    const margin = Number(totals.income) !== 0 ? ((Number(totals.grossProfit) / Number(totals.income)) * 100).toFixed(1) : null;

    return (
        <AccountsPageLayout
            tab="pl"
            filters={filters}
            title="Profit and loss · Accounts"
            description="Income less costs for the period, from the journals your tills post."
            actions={
                <Button variant="outline" asChild>
                    <Link href={route('app.reports.show', { report: 'sales', period: 'custom', from: filters.from, to: filters.to })}>
                        <BarChart3 />
                        Sales summary report
                    </Link>
                </Button>
            }
        >
            <AccountsFilters filters={filters} options={options} update={update} />
            <RefundFixNotice summary={refundFix} fix={filters.fix} update={update} />
            <StatGrid columns={3}>
                <StatCard label="Income" value={money(totals.income)} icon={PoundSterling} />
                <StatCard label="Gross profit" value={money(totals.grossProfit)} hint={margin !== null ? `${margin}% margin` : undefined} icon={TrendingUp} tone="success" />
                <StatCard
                    label="Net profit"
                    value={money(totals.netProfit)}
                    icon={TrendingUp}
                    tone={Number(totals.netProfit) < 0 ? 'danger' : 'primary'}
                />
            </StatGrid>
            {salesCheck.differs && (
                <Alert variant="info">
                    <Info />
                    <AlertTitle>Journal sales differ from the till sales data</AlertTitle>
                    <AlertDescription>
                        <p>
                            Sales in the journals are {money(salesCheck.journals)}; the till sales data (net of refunds, as in Reports) says{' '}
                            {money(salesCheck.salesData)}, a difference of <Amount value={salesCheck.difference} />. Old refund entries, journals
                            dated differently from the sale, or tills still to sync can cause this. Use the sales data figure where you need sales.
                        </p>
                    </AlertDescription>
                </Alert>
            )}
            <StatementSection title="Income" lines={income} total={totals.income} totalLabel="Total income" filters={filters} />
            <StatementSection
                title="Cost of sales"
                description="Stock bought for resale (the 5000s)."
                lines={costOfSales}
                total={totals.costOfSales}
                totalLabel="Total cost of sales"
                filters={filters}
            />
            <StatementTotal label="Gross profit" value={totals.grossProfit} hint={margin !== null ? `${margin}% of income` : undefined} />
            <StatementSection title="Overheads" lines={overheads} total={totals.overheads} totalLabel="Total overheads" filters={filters} />
            <StatementTotal label={Number(totals.netProfit) < 0 ? 'Net loss' : 'Net profit'} value={totals.netProfit} />
            <SectionCard>
                <p className="text-muted-foreground text-sm">
                    Figures come from the tills' journals only: costs paid outside the tills (rent, wages through payroll) are not here unless a till
                    recorded them as an expense. Give your accountant the trial balance for year-end work.
                </p>
            </SectionCard>
        </AccountsPageLayout>
    );
}
