/** Online orders (OS-4). Matches `OnlineStore\Queries\OnlineOrderBoard::for()`, `OnlineOrderDetail::for()` and `Support\OrderRows`. */

export type OrderStatus = 'new' | 'picking' | 'ready' | 'outForDelivery' | 'collected' | 'delivered' | 'notCollected' | 'cancelled';

export type Fulfilment = 'collection' | 'delivery';

/** OS-9: how the order is paid (`OrderRows::payment`). */
export interface OrderPayment {
    method: 'payAtShop' | 'cardOnline' | 'payOnDelivery';
    label: string;
    paid: boolean;
    /** Cancelled after a card payment: refund it in the DNA portal (no refund API in S2). */
    refundNeeded: boolean;
}

export type ColumnKey = 'new' | 'picking' | 'ready' | 'done';

export interface OrderCard {
    id: string;
    reference: string;
    status: OrderStatus;
    statusLabel: string;
    fulfilment: Fulfilment;
    deliveryPostcode: string | null;
    payment: OrderPayment;
    hasNote: boolean;
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
    /** OS-6: a New card to be collected on another day than the board's (the New column shows every new order). */
    otherDay?: boolean;
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
    /** OSA-5: false once the store went off while open orders are still to finish. */
    storeAvailable?: boolean;
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
    /** OSA-18: what the shop did while picking. */
    outcome: 'changed' | 'unavailable' | null;
    orderedQuantity: string | null;
}

export interface OrderDetail extends OrderCard {
    customerName: string;
    customerMobile: string;
    customerEmail: string;
    customerNote: string | null;
    substitutionsOk: boolean;
    shopAddress: string | null;
    deliveryAddress: string | null;
    deliveryNotes: string | null;
    itemsTotal: string;
    deliveryFee: string;
    paidAmount: string | null;
    paymentReference: string | null;
    /** Card payment to give back in the DNA portal (cancelled, or lines taken off). */
    refundDue: string | null;
    canChangeLines: boolean;
    /** OSA-21: the last move can be taken back until `until`. */
    undo: { to: OrderStatus; until: string | null } | null;
    anonymised: boolean;
    linkExpiresAt: string | null;
    next: OrderStatus[];
    lines: OrderLine[];
    history: { status: OrderStatus; at: string | null; note: string | null }[];
}

export interface OnlineOrderShowProps {
    business: string;
    order: OrderDetail;
}
