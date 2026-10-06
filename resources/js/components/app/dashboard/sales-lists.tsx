import { type BusinessData } from '@/components/app/dashboard/types';
import { SegmentedControl } from '@/components/shared/chart-card';
import { number, share } from '@/components/shared/trading/format';
import { LeadersCard, salesDetail, type LeaderItem } from '@/components/shared/trading/leaders-card';
import { formatNumber } from '@/lib/country';
import { FolderTree, Monitor, Package, Store, Users } from 'lucide-react';
import { useState } from 'react';

const qty = (value: string) => formatNumber(Number(value), { maximumFractionDigits: 3 });

/** Sales by shop (all shops) or by till (one shop), DASHBOARD.md §2.3. Selecting a shop switches the portal to it. */
export function ShopsOrTillsCard({ data, onShop }: { data: BusinessData; onShop: (id: string) => void }) {
    const total = data.kpis.totals.net;

    if (data.shops) {
        return (
            <LeadersCard
                title="Sales by shop"
                description="Net sales per shop. Select one to see only that shop."
                icon={Store}
                total={total}
                avatar="square"
                emptyTitle="No shop traded"
                emptyBody="Shops appear here once their tills push sales for these days."
                items={data.shops.map<LeaderItem>((s) => ({
                    id: s.id,
                    name: s.label,
                    subline: `${share(s.net, total).toFixed(1)}% of sales · ${number(s.refundCount)} ${s.refundCount === 1 ? 'refund' : 'refunds'}`,
                    value: s.net,
                    detail: salesDetail(s.transactions, s.averageBasketExVat),
                    onSelect: () => onShop(s.id),
                }))}
            />
        );
    }

    return (
        <LeadersCard
            title="Sales by till"
            description="Net sales per till of the shop."
            icon={Monitor}
            total={total}
            avatar="none"
            emptyTitle="No till traded"
            emptyBody="Tills appear here once they push sales for these days."
            items={(data.tills ?? []).map<LeaderItem>((t) => ({
                id: t.id,
                name: t.label,
                subline: `${number(t.refundCount)} ${t.refundCount === 1 ? 'refund' : 'refunds'}`,
                value: t.net,
                detail: salesDetail(t.transactions, t.averageBasketExVat),
            }))}
        />
    );
}

/** Top 10 products by net sales, refunds taken off (§2.3, §5.4). */
export function TopProductsCard({ data }: { data: BusinessData }) {
    return (
        <LeadersCard
            title="Top products"
            description="By net sales, refunds taken off."
            icon={Package}
            total={data.kpis.totals.net}
            avatar="none"
            emptyTitle="No products sold"
            emptyBody="Your best sellers show here once sales arrive."
            items={data.products.map<LeaderItem>((p) => ({
                id: p.productId,
                name: p.name,
                subline: `${qty(p.qty)} sold${p.department === 'Unassigned' ? '' : ` · ${p.department}`}`,
                value: p.net,
            }))}
        />
    );
}

/** Net sales by the product's current department or category (§2.3); the rest summed so the list adds up. */
export function DepartmentsCard({ data }: { data: BusinessData }) {
    const [by, setBy] = useState<'departments' | 'categories'>('departments');
    const rows = data[by];

    return (
        <LeadersCard
            title={by === 'departments' ? 'Sales by department' : 'Sales by category'}
            description="Net sales, grouped by each product's current one."
            icon={FolderTree}
            total={data.kpis.totals.net}
            avatar="none"
            actions={
                <SegmentedControl
                    label="Group by"
                    value={by}
                    onChange={setBy}
                    options={[
                        { value: 'departments', label: 'Departments' },
                        { value: 'categories', label: 'Categories' },
                    ]}
                />
            }
            emptyTitle={by === 'departments' ? 'No department sales' : 'No category sales'}
            emptyBody="Sales appear here grouped once products are sold."
            items={rows.map<LeaderItem>((r) => ({
                id: r.id ?? 'unassigned',
                name: r.name,
                subline: `${qty(r.qty)} items`,
                value: r.net,
            }))}
        />
    );
}

/** Staff sales (§2.3, §2.7): per till user, with refunds and voided baskets. */
export function StaffCard({ data }: { data: BusinessData }) {
    return (
        <LeadersCard
            title="Staff sales"
            description="Net sales per till user."
            icon={Users}
            total={data.kpis.totals.net}
            avatar="circle"
            emptyTitle="No staff sales"
            emptyBody="Each cashier's sales show here once the tills push them."
            items={data.staff.map<LeaderItem>((s) => ({
                id: s.userId,
                name: s.name,
                subline: [
                    `${number(s.refundCount)} ${s.refundCount === 1 ? 'refund' : 'refunds'}`,
                    s.voidCount > 0 ? `${number(s.voidCount)} voided` : null,
                ]
                    .filter(Boolean)
                    .join(' · '),
                value: s.net,
                detail: salesDetail(s.transactions, s.averageBasketExVat),
            }))}
        />
    );
}
