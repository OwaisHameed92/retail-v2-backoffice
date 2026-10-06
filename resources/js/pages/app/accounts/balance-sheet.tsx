import { AccountsFilters, AccountsPageLayout, Amount, RefundFixNotice } from '@/components/app/accounts/accounts-page';
import { StatementSection, StatementTotal } from '@/components/app/accounts/statement-section';
import { type BalanceSheetProps } from '@/components/app/accounts/types';
import { formatDay } from '@/components/app/pricing/format';
import { useTableQuery } from '@/components/shared/data-table';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { taxText } from '@/lib/country';
import { TriangleAlert } from 'lucide-react';

export default function AccountsBalanceSheet({ assets, liabilities, equity, totals, balanced, refundFix, filters, options }: BalanceSheetProps) {
    const { update } = useTableQuery();
    const empty = assets.length === 0 && liabilities.length === 0 && equity.length === 0;

    return (
        <AccountsPageLayout
            tab="bs"
            filters={filters}
            title="Balance sheet · Accounts"
            description={`What the business owns and owes at ${formatDay(filters.to)}, from every journal your tills have posted up to that date.`}
        >
            <AccountsFilters filters={filters} options={options} update={update} asAt />
            <RefundFixNotice summary={refundFix} fix={filters.fix} update={update} />
            {!empty && !balanced && (
                <Alert variant="destructive">
                    <TriangleAlert />
                    <AlertTitle>
                        The balance sheet is out by <Amount value={totals.difference} />
                    </AlertTitle>
                    <AlertDescription>Some journal lines may not have synced yet. Wait for every till to sync, then check again.</AlertDescription>
                </Alert>
            )}
            <StatementSection title="Assets" description="Cash, bank, stock and money owed to you." lines={assets} total={totals.assets} totalLabel="Total assets" filters={filters} />
            <StatementSection
                title="Liabilities"
                description={taxText('VAT, customer deposits and charity money held, and suppliers you owe.')}
                lines={liabilities}
                total={totals.liabilities}
                totalLabel="Total liabilities"
                filters={filters}
            />
            <StatementTotal label="Net assets" value={totals.netAssets} hint="Assets less liabilities" />
            <StatementSection
                title="Capital"
                description="The owner's money in the business, and profit not yet closed off at a year end."
                lines={[...equity, { code: '—', name: 'Profit and loss to date (not yet closed off)', amount: totals.profit }]}
                total={totals.capital}
                totalLabel="Total capital"
                filters={filters}
            />
        </AccountsPageLayout>
    );
}
