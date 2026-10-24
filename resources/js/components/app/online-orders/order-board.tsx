import { cn } from '@/lib/utils';
import { CheckCheck, Inbox, PackageCheck, ShoppingBasket, type LucideIcon } from 'lucide-react';
import { OrderCard } from './order-card';
import { type Board, type ColumnKey } from './types';

const COLUMNS: { key: ColumnKey; title: string; icon: LucideIcon; empty: string }[] = [
    { key: 'new', title: 'New', icon: Inbox, empty: 'No new orders. New ones appear here by themselves.' },
    { key: 'picking', title: 'Picking', icon: ShoppingBasket, empty: 'Nothing being picked.' },
    { key: 'ready', title: 'Ready', icon: PackageCheck, empty: 'Nothing waiting to be collected or delivered.' },
    { key: 'done', title: 'Done', icon: CheckCheck, empty: 'Collected, delivered and cancelled orders show here.' },
];

/**
 * The four columns: New, Picking, Ready (by slot; with deliveries out for delivery, OS-9) and Done (collected,
 * delivered, not collected or cancelled that day, newest first).
 */
export function OrderBoard({
    board,
    isToday,
    showShop,
    highlighted,
}: {
    board: Board;
    isToday: boolean;
    showShop: boolean;
    highlighted: Set<string>;
}) {
    return (
        <div className="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {COLUMNS.map(({ key, title, icon: Icon, empty }) => {
                const column = board.columns[key];
                const label = key === 'done' && isToday ? 'Done today' : title;
                const hidden = column.count - column.cards.length;

                return (
                    <section key={key} aria-labelledby={`column-${key}`} className="bg-subtle rounded-card flex flex-col gap-3 border p-3">
                        <h2 id={`column-${key}`} className="flex items-center justify-between gap-2 px-1 text-sm font-semibold">
                            <span className="inline-flex items-center gap-2">
                                <Icon className="text-muted-foreground size-4" aria-hidden />
                                {label}
                            </span>
                            <span
                                className={cn(
                                    'inline-flex h-6 min-w-6 items-center justify-center rounded-full px-2 text-xs tabular-nums',
                                    key === 'new' && column.count > 0 ? 'bg-primary text-primary-foreground' : 'bg-card text-muted-foreground border',
                                )}
                            >
                                {column.count}
                            </span>
                        </h2>
                        {column.cards.length === 0 ? (
                            <p className="text-muted-foreground rounded-lg border border-dashed px-3 py-6 text-center text-xs">{empty}</p>
                        ) : (
                            column.cards.map((order) => (
                                <OrderCard key={order.id} order={order} showShop={showShop} highlight={highlighted.has(order.id)} />
                            ))
                        )}
                        {hidden > 0 && (
                            <p className="text-muted-foreground px-1 text-xs">{hidden} more not shown. Pick a shop to narrow the board.</p>
                        )}
                    </section>
                );
            })}
        </div>
    );
}
