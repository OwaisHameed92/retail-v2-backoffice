import { type Paginated } from '@/components/shared/data-table';

/** Matches `InvoiceImportStatus`. */
export type InvoiceImportStatus = 'reading' | 'review' | 'confirmed' | 'failed' | 'discarded';

/** One line of `InvoiceDraft`. Money is strings in pounds ex VAT. */
export type DraftLine = {
    description: string;
    barcode: string | null;
    supplierCode: string | null;
    quantity: string;
    packSize: number;
    unitPrice: string | null;
    vatRate: string | null;
    lineNet: string | null;
    productId: string | null;
    pinned: boolean;
    matchedBy: 'barcode' | 'supplierCode' | 'sku' | 'name' | 'user' | null;
    confidence: number;
    catalogueName: string | null;
};

/** `InvoiceDraft`. */
export type InvoiceDraft = {
    documentType: 'invoice' | 'deliveryNote';
    supplierName: string | null;
    supplierVatNumber: string | null;
    supplierId: string | null;
    supplierPinned: boolean;
    invoiceNumber: string | null;
    invoiceDate: string | null;
    orderReference: string | null;
    purchaseOrderId: string | null;
    goodsReceiptId: string | null;
    documentPinned: boolean;
    netTotal: string | null;
    vatTotal: string | null;
    grossTotal: string | null;
    lines: DraftLine[];
    readingNotes: string[];
};

export interface AnalysedProduct {
    id: string;
    name: string;
    sku: string | null;
    costPrice: string;
    vatRate: string | null;
}

/** `InvoiceAnalysis::of()`. */
export interface InvoiceIssue {
    level: 'error' | 'warning' | 'info';
    code: string;
    message: string;
    line: number | null;
}

export interface CostChange {
    productId: string;
    name: string;
    sku: string | null;
    line: number;
    from: string;
    to: string;
    changePercent: string | null;
}

export interface InvoiceAnalysis {
    lines: {
        calcNet: string | null;
        units: string;
        costPerItem: string | null;
        vatRate: string | null;
        product: AnalysedProduct | null;
        reference: { units: string; unitCost: string } | null;
    }[];
    totals: { net: string; vat: string; gross: string };
    issues: InvoiceIssue[];
    costChanges: CostChange[];
    reference: 'order' | 'delivery' | null;
}

export interface ImportResult {
    order: { id: string; reference: string; status: string | null; lines: number } | null;
    costUpdates: { productId: string; name: string; from: string; to: string }[];
    lines: number;
    net: string;
    gross: string;
}

export interface Option {
    id: string;
    name?: string;
    label?: string;
}

/** `InvoiceReviewPage::for()`. */
export interface InvoiceReviewProps {
    import: {
        id: string;
        status: InvoiceImportStatus;
        statusLabel: string;
        method: 'ai' | 'manual';
        error: string | null;
        shop: { id: string | null; name: string };
        file: { name: string | null; mime: string | null; size: number | null } | null;
        filePurged: boolean;
        uploadedBy: string | null;
        createdAt: string | null;
        readAt: string | null;
        tokens: number;
        confirmedAt: string | null;
        result: ImportResult | null;
    };
    draft: InvoiceDraft | null;
    analysis: InvoiceAnalysis | null;
    suppliers: { id: string; name: string }[];
    orders: { id: string; label: string }[];
    deliveries: { id: string; label: string }[];
    linked: {
        order: { id: string; reference: string; status: string | null } | null;
        delivery: { id: string; reference: string; date: string } | null;
    } | null;
    vatRates: { id: string; name: string; percentage: string }[];
    search: string | null;
    results: { id: string; name: string; sku: string | null; costPrice: string }[];
    can: { edit: boolean; confirm: boolean; discard: boolean; retry: boolean; order: boolean; costs: boolean };
}

export interface ImportRow {
    id: string;
    status: InvoiceImportStatus;
    statusLabel: string;
    method: 'ai' | 'manual';
    shop: string | null;
    supplier: string | null;
    supplierName: string | null;
    invoiceNumber: string | null;
    invoiceDate: string | null;
    gross: string | null;
    fileName: string | null;
    uploadedBy: string | null;
    createdAt: string | null;
    lines: number;
}

/** `InvoiceImportList::for()`. */
export interface InvoiceImportProps {
    access: { inPlan: boolean; reader: { available: boolean; message: string | null }; oneShop: boolean };
    shops: { id: string; name: string; code: string }[];
    limits: { maxKb: number; types: string[]; retentionDays: number };
    filters: { status: string | null };
    statuses: { value: InvoiceImportStatus; label: string }[];
    imports: Paginated<ImportRow>;
}

export const IMPORT_TONES = {
    reading: 'info',
    review: 'warning',
    confirmed: 'success',
    failed: 'danger',
    discarded: 'neutral',
} as const;
