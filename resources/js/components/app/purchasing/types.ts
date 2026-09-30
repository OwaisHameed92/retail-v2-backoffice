import { type Paginated } from '@/components/shared/data-table';

/** Matches `Purchasing\Queries\PurchasingPage::KINDS`. */
export type PurchasingKind = 'orders' | 'deliveries' | 'invoices' | 'credit-notes' | 'returns' | 'payments' | 'rebates';

/** The document kinds with a detail page of their own (`DocumentDetail::MODELS`). */
export type DocumentKind = 'deliveries' | 'invoices' | 'credit-notes' | 'returns';

export interface ShopOption {
    id: string;
    name: string;
    code: string;
}

export interface NamedOption {
    id: string;
    name: string;
}

/** `PurchasingPage::shared()`. */
export interface PurchasingShared {
    shops: ShopOption[];
    suppliers: NamedOption[];
    oneShop: boolean;
    can: { manage: boolean };
}

export interface PurchasingStat {
    label: string;
    value: string;
    format: 'count' | 'money';
    tone: 'primary' | 'success' | 'warning' | 'danger' | 'neutral';
    hint?: string;
}

/** One list row: common keys plus the kind's own (see the `*Rows` classes). */
export interface DocumentRow {
    id: string;
    reference: string;
    status: string | null;
    shop?: string | null;
    supplier: string;
    date?: string | null;
    gross: string;
    [key: string]: unknown;
}

export interface PurchasingIndexProps extends PurchasingShared {
    kind: PurchasingKind;
    tabs: Record<PurchasingKind, number>;
    stats: PurchasingStat[];
    filters: { shop: string | null; supplier: string | null; status: string | null; origin: string | null };
    statuses: string[];
    rows: Paginated<DocumentRow>;
}

export interface ProductName {
    name: string;
    sku: string | null;
}

export interface LinkedDocument {
    kind: PurchasingKind;
    id: string;
    label: string;
    reference: string | null;
    status: string | null;
}

/** `OrderDetail::for()`. */
export interface OrderShowProps extends PurchasingShared {
    order: {
        id: string;
        reference: string;
        orderNo: string | null;
        origin: 'branch' | 'headOffice';
        status: string | null;
        shop: string | null;
        shopId: string | null;
        supplier: string;
        supplierId: string | null;
        expectedDate: string | null;
        sentAt: string | null;
        cancelledAt: string | null;
        cancelReason: string | null;
        notes: string | null;
        createdAt: string | null;
        updatedAt: string | null;
        draftedHere: boolean;
        withPortal: boolean;
    };
    totals: { net: string; vat: string; gross: string; discount: string | null; ordered: string; received: string };
    lines: {
        id: string;
        product: ProductName;
        cases: number;
        caseQty: number;
        units: string;
        received: string;
        unitCost: string;
        vatPercentage: string;
        net: string;
    }[];
    deliveries: { id: string; reference: string; status: string | null; date: string | null; gross: string }[];
    invoices: { id: string; reference: string; status: string | null; date: string | null; gross: string }[];
    lockedReason: string | null;
    can: { manage: boolean; edit: boolean; send: boolean; cancel: boolean };
}

/** `DocumentDetail::for()`. */
export interface DocumentShowProps {
    kind: DocumentKind;
    document: {
        id: string;
        reference: string;
        status: string | null;
        date: string | null;
        shop: string | null;
        supplier: string;
        supplierId: string | null;
        note: string | null;
    };
    facts: { label: string; value: string; format: 'date' | 'datetime' | 'money' | 'text' }[];
    columns: { key: string; label: string }[];
    lines: ({ id: string; product: ProductName; note: string | null; flag: string | null } & Record<string, unknown>)[];
    totals: { net: string | null; vat: string | null; gross: string | null };
    links: LinkedDocument[];
}

export type OrderFormLine = {
    productId: string;
    name: string;
    sku: string | null;
    orderedCases: number;
    caseQty: number;
    looseUnits: number;
    unitCost: string;
    vatRateId: string;
    onHand: string | null;
};

export interface CatalogueProduct {
    productId: string;
    name: string;
    sku: string | null;
    supplierSku: string | null;
    caseQty: number;
    unitCost: string;
    vatRateId: string;
    onHand: string | null;
    suggestedCases: number;
}

/** `OrderForm::for()`. */
export interface OrderFormProps {
    order: {
        id: string;
        reference: string;
        status: string | null;
        supplierId: string | null;
        expectedDate: string | null;
        notes: string;
        lines: OrderFormLine[];
    } | null;
    shopId: string | null;
    supplierId: string | null;
    shops: ShopOption[];
    suppliers: (NamedOption & { leadDays: number | null; minimumOrder: string | null })[];
    vatRates: (NamedOption & { percentage: string })[];
    suggestions: CatalogueProduct[];
    search: string | null;
    results: CatalogueProduct[];
}

export interface SupplierBalance {
    id: string;
    name: string;
    invoiced: string;
    credited: string;
    paid: string;
    balance: string;
}

export interface StatementsProps extends PurchasingShared {
    balances: SupplierBalance[];
    summary: { invoiced: string; credited: string; paid: string; balance: string; owed: string; inCredit: string };
    shop: string | null;
}

export interface StatementEntry {
    id: string;
    type: 'invoice' | 'credit' | 'payment';
    kind: DocumentKind | null;
    date: string;
    reference: string;
    detail: string | null;
    shopId: string | null;
    debit: string;
    credit: string;
    balance: string;
}

/** `SupplierStatement::for()`. */
export interface StatementProps extends PurchasingShared {
    supplier: { id: string; name: string; account: string | null; terms: number | null };
    period: { from: string; to: string };
    opening: string;
    entries: StatementEntry[];
    totals: { invoiced: string; credited: string; paid: string; reduced: string };
    closing: string;
    shop: string | null;
}
