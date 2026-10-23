/** Online orders (OS-4). Matches `OnlineStore\Queries\OnlineOrderBoard::for()`, `OnlineOrderDetail::for()` and `Support\OrderRows`. */

export type OrderStatus = 'new' | 'picking' | 'ready' | 'collected' | 'cancelled';

export type ColumnKey = 'new' | 'picking' | 'ready' | 'done';

export interface OrderCard {
    id: string;
    reference: string;
    status: OrderStatus;
    /** "Asha P." */
    customer: string;
    itemCount: number;
    /** "Beans, Milk +3 more" */
    preview: string;
    hasWeighed: boolean;
    total: string;
    shop: string;
    /** ISO UTC. */
    collectFrom: string;
    collectTo: string;
    placedAt: string | null;
    doneAt: string | null;
    cancelReason: string | null;
    /** New and placed in the last 15 minutes. */
    fresh: boolean;
    /** Still open after its slot ended. */
    late: boolean;
    /** Not ready yet and its slot starts within 30 minutes. */
    dueSoon: boolean;
}

export interface BoardColumn {
    count: number;
    cards: OrderCard[];
}

export interface BoardStats {
    orders: number;
    value: string;
    average: string | null;
    waiting: number;
}

export interface Board {
    columns: Record<ColumnKey, BoardColumn>;
    stats: BoardStats;
    /** Open orders on the coming days (today's board only). */
    later: { day: string; count: number }[];
    refreshedAt: string;
}

export interface BoardFilters {
    shop: string | null;
    day: string;
    today: string;
    isToday: boolean;
    shopLocked: boolean;
}

export interface OnlineOrdersIndexProps {
    store: { isLive: boolean; takingOrders: boolean } | null;
    setupUrl: string | null;
    filters: BoardFilters;
    options: { shops: { value: string; label: string }[] };
    board: Board;
}

export interface OrderLine {
    id: string;
    name: string;
    /** "2", or kg for a weighed line ("0.75"). */
    quantity: string;
    isWeighed: boolean;
    unitPrice: string;
    lineTotal: string;
}

export interface OrderDetail extends OrderCard {
    customerName: string;
    customerMobile: string;
    customerEmail: string;
    shopAddress: string | null;
    next: OrderStatus[];
    lines: OrderLine[];
    history: { status: OrderStatus; at: string | null; note: string | null }[];
}

export interface OnlineOrderShowProps {
    business: string;
    order: OrderDetail;
}
