import { type TableMeta } from '@/components/shared/data-table';
import { formatMoney } from '@/lib/country';

export interface Option {
    value: string;
    label: string;
}

export type LabelOptionKey =
    | 'show_unit_price'
    | 'show_barcode'
    | 'show_offer_name'
    | 'show_shop_name'
    | 'show_date'
    | 'show_pmp'
    | 'show_drs'
    | 'highlight_offers';

export type LabelOptions = Record<LabelOptionKey, boolean>;

export interface LabelStock {
    key: string;
    name: string;
    kind: 'a4' | 'roll';
    ref: string | null;
    pageWidth: number;
    pageHeight: number;
    cols: number;
    rows: number;
    width: number;
    height: number;
    top: number;
    left: number;
    hPitch: number;
    vPitch: number;
    perPage: number;
}

export interface LabelTemplate {
    id: string;
    name: string;
    stock: string;
    branchId: string | null;
    isDefault: boolean;
    options: LabelOptions;
}

export interface QueueRow {
    id: string;
    productId: string;
    name: string;
    sku: string | null;
    price: string | null;
    ownPrice: boolean;
    reason: string;
    reasonLabel: string;
    detail: string | null;
    copies: number;
    timesQueued: number;
    queuedAt: string;
    dueAt: string | null;
    printedAt: string | null;
}

export interface LabelsPageProps {
    shops: { id: string; name: string }[];
    shop: { id: string; name: string } | null;
    restrictedShop: string | null;
    filters: { view: 'waiting' | 'printed'; reason: string | null };
    reasons: Option[];
    stocks: LabelStock[];
    items: { data: QueueRow[]; meta: TableMeta };
    counts: { waiting: number; priceChanges: number; offers: number; manual: number; later: number; printedWeek: number };
    templates: LabelTemplate[];
    departments: Option[];
    suppliers: Option[];
}

export interface LabelContent {
    id: string;
    productId: string;
    name: string;
    price: string;
    priceText: string;
    unitPrice: string | null;
    barcode: { type: 'ean13' | 'code128'; text: string; svg: string } | null;
    offer: string | null;
    offerUntil: string | null;
    pmp: string | null;
    deposit: string | null;
    shop: string;
    date: string;
    copies: number;
}

export interface LabelPreview {
    labels: LabelContent[];
    template: LabelTemplate;
    stock: LabelStock;
    count: number;
    pages: number;
    skip: number;
}

export const OPTION_LABELS: { key: LabelOptionKey; label: string; help: string }[] = [
    { key: 'show_unit_price', label: 'Unit price', help: 'Per kg, litre, 100 g or 100 ml when the product has a size.' },
    { key: 'show_barcode', label: 'Barcode', help: 'EAN-13 or Code 128. Hidden on the smallest labels.' },
    {
        key: 'show_offer_name',
        label: 'Offer',
        get help() {
            return `The live offer at the shop, e.g. "3 for ${formatMoney(2)}".`;
        },
    },
    { key: 'highlight_offers', label: 'Highlight offers', help: 'A yellow band across the top of offer labels.' },
    { key: 'show_shop_name', label: 'Shop name', help: 'Small, at the bottom.' },
    { key: 'show_date', label: 'Date', help: 'When the price was printed.' },
    { key: 'show_pmp', label: 'Price-marked pack', help: 'The PMP price printed on the pack.' },
    { key: 'show_drs', label: 'Deposit', help: 'The deposit on returnable drinks.' },
];
