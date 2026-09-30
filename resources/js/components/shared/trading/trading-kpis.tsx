import { changeDelta, money, moneyShort, number } from '@/components/shared/trading/format';
import { type SalesDashboardData } from '@/components/shared/trading/types';
import { KpiCard, KpiGrid } from '@/components/shared/kpi-card';
import { StatCard } from '@/components/shared/stat-card';
import { Banknote, Percent, PoundSterling, Receipt, ReceiptText, RotateCcw, ShoppingBasket, Tags, TrendingUp, XCircle } from 'lucide-react';

/** "£11,540 previous period" under a headline tile. */
function previousFooter(previous: string | null, label: string, format: (v: string) => string) {
    if (previous === null || label === '') {
        return undefined;
    }

    return `${format(previous)} ${label.replace(/^vs /, '')}`;
}

/**
 * The headline tiles (DASHBOARD.md §2.2): sales inc VAT, net sales (the till's "Sales"), transactions and average
 * basket, each with its change and a sparkline; then the secondary figures (VAT, takings, refunds, discounts,
 * voids, gross profit). Refunds and voids are good when they go down.
 */
export function TradingKpis({ data }: { data: SalesDashboardData }) {
    const { headline, totals, changes } = data.kpis;
    const versus = data.range.compareLabel + (data.range.isToday && data.range.compareFrom ? ` to ${String(data.range.hour + 1).padStart(2, '0')}:00` : '');
    const label = data.range.compareFrom ? versus : '';
    const empty = totals.transactions === 0 && totals.refundCount === 0;
    // The range ends today: the sparkline's last point (today, or the hour now) is "so far", not a drop.
    const partial = data.range.to === data.range.today;

    return (
        <div className="flex flex-col gap-4">
            <KpiGrid>
                <KpiCard
                    label="Sales (inc VAT)"
                    icon={PoundSterling}
                    tone="primary"
                    value={empty ? null : moneyShort(headline.gross.value)}
                    emptyText="No sales in this period"
                    delta={changeDelta(headline.gross.change, label)}
                    series={headline.gross.series}
                    seriesPartial={partial}
                    footer={previousFooter(headline.gross.previous, label, moneyShort)}
                />
                <KpiCard
                    label="Net sales (ex VAT)"
                    icon={TrendingUp}
                    tone="success"
                    value={empty ? null : moneyShort(headline.net.value)}
                    emptyText="No sales in this period"
                    delta={changeDelta(headline.net.change, label)}
                    series={headline.net.series}
                    seriesPartial={partial}
                    footer={previousFooter(headline.net.previous, label, moneyShort)}
                />
                <KpiCard
                    label="Transactions"
                    icon={Receipt}
                    tone="info"
                    value={empty ? null : number(headline.transactions.value)}
                    emptyText="No sales in this period"
                    delta={changeDelta(headline.transactions.change, label)}
                    series={headline.transactions.series}
                    seriesPartial={partial}
                    footer={previousFooter(headline.transactions.previous, label, number)}
                />
                <KpiCard
                    label="Average basket (ex VAT)"
                    icon={ShoppingBasket}
                    tone="violet"
                    value={headline.averageBasket.value === null ? null : money(headline.averageBasket.value)}
                    emptyText="No transactions yet"
                    delta={changeDelta(headline.averageBasket.change, label)}
                    series={headline.averageBasket.series}
                    seriesPartial={partial}
                    footer={headline.averageBasket.secondary ? `${money(headline.averageBasket.secondary)} inc VAT` : undefined}
                />
            </KpiGrid>

            <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 2xl:grid-cols-6">
                <StatCard label="VAT" value={moneyShort(totals.vat)} icon={Percent} tone="neutral" delta={changeDelta(changes?.vat, label)} />
                <StatCard
                    label="Takings"
                    value={moneyShort(totals.takings)}
                    icon={Banknote}
                    tone="success"
                    delta={changeDelta(changes?.takings, label)}
                    hint={Number(totals.containerDeposits) !== 0 ? `of which deposits ${money(totals.containerDeposits)}` : 'Payments less change and cashback'}
                />
                <StatCard
                    label="Refunds"
                    value={moneyShort(totals.refundGross)}
                    icon={RotateCcw}
                    tone="warning"
                    delta={changeDelta(changes?.refundGross, label, 'down')}
                    hint={`${number(totals.refundCount)} ${totals.refundCount === 1 ? 'refund' : 'refunds'}`}
                />
                <StatCard
                    label="Discounts"
                    value={moneyShort(totals.discount)}
                    icon={Tags}
                    tone="primary"
                    delta={changeDelta(changes?.discount, label, 'down')}
                    hint={`Promotions ${money(totals.promo)}`}
                />
                <StatCard
                    label="Voided baskets"
                    value={number(totals.voidCount)}
                    icon={XCircle}
                    tone="danger"
                    delta={changeDelta(changes?.voidTotal, label ? `value ${label}` : '', 'down')}
                    hint={`Worth ${money(totals.voidTotal)}, not in sales`}
                />
                <StatCard
                    label="Gross profit"
                    value={totals.grossProfit === null ? '—' : moneyShort(totals.grossProfit)}
                    icon={ReceiptText}
                    tone="success"
                    hint={
                        totals.grossProfit === null
                            ? 'No costs recorded on the tills'
                            : `${Number(totals.net) !== 0 ? ((Number(totals.grossProfit) / Number(totals.net)) * 100).toFixed(1) : '0.0'}% margin on net sales`
                    }
                />
            </div>
        </div>
    );
}
