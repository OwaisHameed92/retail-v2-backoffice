/** Matches App\Domain\Sales\Queries\SaleList::for() and SaleReceipt::for() (module 4.6). */

export interface Option {
    value: string;
    label: string;
}

export interface CustomerRef {
    id: string;
    name: string;
    cardNo: string | null;
}

export interface SaleRow {
    id: string;
    receiptNumber: string;
    type: string | null;
    status: string | null;
    day: string;
    at: string | null;
    shop: string | null;
    till: string | null;
    staff: string | null;
    customer: CustomerRef | null;
    items: number;
    total: string;
    discount: string;
    tenders: string[];
    refunded: boolean;
}

export interface SaleFiltersState {
    from: string;
    to: string;
    shop: string | null;
    till: string | null;
    staff: string | null;
    status: 'completed' | 'refunds' | 'voided' | null;
    payment: string | null;
    min: string | null;
    max: string | null;
    customer: string | null;
    receipt: string | null;
    shopLocked: boolean;
}

export interface SalesExportRow {
    id: string;
    status: 'queued' | 'running' | 'ready' | 'failed';
    rows: number;
    from: string | null;
    to: string | null;
    createdAt: string | null;
    downloadable: boolean;
}

export interface SalesIndexProps {
    sales: { data: SaleRow[]; older: string | null; newer: string | null; count: number; capped: boolean; perPage: number };
    filters: SaleFiltersState;
    options: { shops: Option[]; tills: Option[]; staff: Option[]; payments: Option[] };
    exports: SalesExportRow[];
    exportStreamLimit: number;
    canViewCustomers: boolean;
}

export interface ReceiptLine {
    id: string;
    position: number;
    productId: string | null;
    name: string;
    barcode: string | null;
    qty: string;
    unitPrice: string;
    goodsTotal: string;
    lineDiscount: string;
    ownDiscount: string;
    discountSource: string | null;
    discountReason: string | null;
    promotionName: string | null;
    promotionDiscount: string;
    couponDiscount: string;
    deposit: string;
    vatRate: string;
    vatAmount: string;
    lineTotal: string;
    reason: string | null;
    isRefundLine: boolean;
    flags: string[];
}

export interface ReceiptPayment {
    id: string;
    name: string;
    kind: 'points' | 'deposit' | 'cash' | 'card' | 'voucher' | 'account' | 'other';
    amount: string;
    cashback: string;
    change: string;
    status: string | null;
    scheme: string | null;
    last4: string | null;
    authCode: string | null;
    reference: string | null;
    pointsCustomer: CustomerRef | null;
    offline: boolean;
    currency: { code: string; amount: string; rate: string } | null;
}

export interface SaleBrief {
    id: string;
    receiptNumber: string;
    type: string | null;
    status: string | null;
    total: string;
    at: string | null;
}

export interface SaleEvent {
    id: string;
    action: string;
    at: string | null;
    userId: string | null;
    user: string | null;
    reason: string | null;
    details: { label: string; value: string }[];
    matchedByTime: boolean;
}

export interface SaleShowProps {
    sale: {
        id: string;
        receiptNumber: string;
        number: number;
        type: string | null;
        status: string | null;
        day: string | null;
        completedAt: string | null;
        startedAt: string | null;
        voidedAt: string | null;
        receivedAt: string | null;
        noReceipt: boolean;
        shop: string | null;
        till: string | null;
        staff: string | null;
        approvedBy: string | null;
        voidedBy: string | null;
        reason: string | null;
        voidReason: string | null;
        refundPriceBasis: string | null;
        customer: CustomerRef | null;
    };
    totals: {
        subtotal: string;
        discount: string;
        promo: string;
        deposit: string;
        vat: string;
        net: string;
        total: string;
        tendered: string;
        cashback: string;
        change: string;
    };
    lines: ReceiptLine[];
    vat: { code: string | null; rate: string; net: string; vat: string; gross: string }[];
    payments: ReceiptPayment[];
    original: SaleBrief | null;
    linked: SaleBrief[];
    events: SaleEvent[];
    canViewCustomers: boolean;
    canViewProducts: boolean;
}
