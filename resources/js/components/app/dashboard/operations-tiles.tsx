import { type Operations } from '@/components/app/dashboard/types';
import { money, number } from '@/components/shared/trading/format';
import { StatCard } from '@/components/shared/stat-card';
import { type ShopsStatus } from '@/components/till-health/types';
import { PackageX, ShoppingBag, Wallet, Wifi } from 'lucide-react';

/** Tills online now, "n of m", from the 2.7 health rows (main till: shop synced; others: licence check). */
function tillsOnline(status: ShopsStatus | null): { online: number; total: number } {
    const tills = (status?.shops ?? []).flatMap((shop) => shop.tills);

    return { online: tills.filter((till) => till.health?.state === 'online').length, total: tills.length };
}

/**
 * The Business-panel tiles that are not sales (DASHBOARD.md §2.2, §2.5, §2.6): cash variance of the shifts closed in
 * the range (negative = short, red), low stock now, tills online now, web orders ready to collect.
 */
export function OperationsTiles({ operations, status }: { operations: Operations; status: ShopsStatus | null }) {
    const { cash, lowStock, ordersReady } = operations;
    const variance = Number(cash.variance);
    const tills = tillsOnline(status);

    return (
        <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <StatCard
                label="Cash variance"
                value={
                    cash.shifts === 0 ? (
                        '—'
                    ) : (
                        <span className={variance < 0 ? 'text-danger' : undefined}>
                            {variance > 0 ? '+' : ''}
                            {money(cash.variance)}
                        </span>
                    )
                }
                icon={Wallet}
                tone={variance < 0 ? 'danger' : 'success'}
                hint={
                    cash.shifts === 0
                        ? 'No shifts closed in this period'
                        : `${number(cash.shifts)} ${cash.shifts === 1 ? 'shift' : 'shifts'} closed${cash.shortShifts > 0 ? `, ${number(cash.shortShifts)} short` : ''}`
                }
            />
            <StatCard
                label="Low stock"
                value={number(lowStock.total)}
                icon={PackageX}
                tone={lowStock.total > 0 ? 'warning' : 'neutral'}
                hint={lowStock.total > 0 ? 'At or below the reorder point, now' : 'Nothing at its reorder point'}
            />
            <StatCard
                label="Tills online"
                value={tills.total === 0 ? '—' : `${number(tills.online)} of ${number(tills.total)}`}
                icon={Wifi}
                tone={tills.total > 0 && tills.online < tills.total ? 'warning' : 'success'}
                hint={tills.total === 0 ? 'No tills set up yet' : tills.online === tills.total ? 'Every till is online' : 'See Shops and tills below'}
            />
            <StatCard
                label="Orders to collect"
                value={number(ordersReady)}
                icon={ShoppingBag}
                tone={ordersReady > 0 ? 'primary' : 'neutral'}
                hint={ordersReady > 0 ? 'Ready and waiting in the shop' : 'No orders waiting'}
            />
        </div>
    );
}
