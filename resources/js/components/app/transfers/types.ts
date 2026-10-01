import { type ProductName, type PurchasingStat, type ShopOption } from '@/components/app/purchasing/types';
import { type Paginated } from '@/components/shared/data-table';

/** `TransferState::STATES`. */
export type TransferStatus = 'requested' | 'dispatched' | 'inTransit' | 'received' | 'partlyReceived' | 'cancelled';

/** `TransferState::RELAY`: the transfer's relay to the receiving shop's till (or the receipt's back to the sender). */
export type RelayState = 'notRelayed' | 'waiting' | 'sent' | 'stored' | 'received';

export interface TransferFilters {
    shop: string | null;
    flow: 'in' | 'out' | null;
    status: TransferStatus | null;
    from: string | null;
    to: string | null;
}

/** `TransferList::shared()`. */
export interface TransferShared {
    shops: ShopOption[];
    oneShop: boolean;
    statuses: TransferStatus[];
}

export interface TransferRow {
    id: string;
    reference: string;
    from: string;
    fromId: string;
    to: string;
    toId: string;
    direction: 'in' | 'out' | null;
    status: TransferStatus;
    relay: RelayState;
    isReturn: boolean;
    requestedAt: string | null;
    dispatchedAt: string | null;
    receivedAt: string | null;
    lines: number;
    value: string;
    /** The till's `StockTransferReceipt.varianceCost`: sent − received at cost, positive = lost in transit. */
    varianceCost: string | null;
    discrepancies: number;
}

export interface TransferIndexProps extends TransferShared {
    filters: TransferFilters;
    stats: PurchasingStat[];
    rows: Paginated<TransferRow>;
}

export interface TransferLine {
    id: string;
    product: ProductName;
    requested: string | null;
    sent: string;
    received: string | null;
    variance: string | null;
    unitCost: string;
    sentValue: string;
    receivedValue: string | null;
    varianceValue: string | null;
    discrepancy: boolean;
    missingOnReceipt: boolean;
}

export interface TransferShowProps {
    transfer: {
        id: string;
        reference: string;
        status: TransferStatus;
        tillStatus: string | null;
        from: string;
        fromId: string;
        to: string;
        toId: string;
        isReturn: boolean;
        returnOf: { id: string; reference: string } | null;
        note: string | null;
        requestedAt: string | null;
        requestedBy: string | null;
        dispatchedAt: string | null;
        dispatchedBy: string | null;
        dispatchedCost: string;
        lineCount: number;
        updatedAt: string | null;
    };
    receipt: { status: string | null; receivedAt: string | null; receivedBy: string | null; closedAt: string | null; note: string | null } | null;
    lines: TransferLine[];
    totals: {
        sent: string;
        received: string | null;
        variance: string | null;
        sentValue: string;
        receivedValue: string | null;
        /** Sum of the lines' difference at cost (received − sent). */
        varianceValue: string | null;
        /** The till's receipt `varianceCost`: sent − received at cost, positive = lost in transit. As sent. */
        varianceCost: string | null;
        discrepancies: number;
    };
    relay: {
        toShop: string;
        fromShop: string;
        transfer: RelayState;
        transferLines: number;
        receipt: RelayState;
        toLastPullAt: string | null;
        fromLastPullAt: string | null;
    };
}

export interface DiscrepancyTotals {
    transfers: number;
    discrepant: number;
    short: string;
    over: string;
    sentValue: string;
    /** The receipts' `varianceCost` (the till's), summed: positive = lost in transit. */
    varianceCost: string;
}

export interface DiscrepancyLine {
    id: string;
    transferId: string;
    reference: string;
    from: string;
    to: string;
    receivedAt: string | null;
    product: ProductName;
    unitCost: string;
    sent: string;
    received: string;
    variance: string;
    sentValue: string;
    receivedValue: string;
    varianceValue: string;
}

export interface DiscrepancyProps extends TransferShared {
    filters: TransferFilters;
    summary: DiscrepancyTotals;
    routes: (DiscrepancyTotals & { from: string; to: string })[];
    lines: DiscrepancyLine[];
    lineCount: number;
    truncated: boolean;
}
