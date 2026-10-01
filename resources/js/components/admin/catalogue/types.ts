import { type Paginated } from '@/components/shared/data-table';

export interface Option {
    value: string;
    label: string;
}

/** MasterRow::of() */
export interface MasterRow {
    id: string;
    barcode: string;
    name: string;
    brand: string | null;
    size: string | null;
    department: string | null;
    category: string | null;
    vatRate: string | null;
    rrp: string | null;
    ageRule: string;
    ageLabel: string | null;
    imageUrl: string | null;
    inStarterPacks: boolean;
    source: 'starter' | 'import' | 'contribution' | 'admin';
    sourceLabel: string;
    sourceRef: string | null;
    updatedAt: string | null;
}

export interface DepartmentCount extends Option {
    count: number;
}

/** MasterCatalogueList::for() */
export interface MasterIndexProps {
    products: Paginated<MasterRow>;
    filters: { source: string | null; department: string | null; duplicates: boolean };
    sources: Option[];
    departments: DepartmentCount[];
    counts: { total: number; bySource: Record<string, number>; pending: number; duplicates: number };
}

/** MasterProductForm values = MasterProductRequest fields. */
export type MasterValues = {
    barcode: string;
    name: string;
    brand: string;
    size_value: string;
    size_unit: string;
    pack_qty: string;
    department: string;
    category: string;
    vat_rate: string;
    rrp: string;
    age_rule: string;
    image_url: string;
    in_starter_packs: boolean;
};

export interface MasterOptions {
    units: Option[];
    ageRules: Option[];
    departments: string[];
    categories: string[];
    vatRates: Option[];
}

export interface MasterFormProps {
    product:
        | (MasterRow & {
              mergedInto: { id: string; name: string; barcode: string } | null;
              aliases: string[];
              sameName: MasterRow[];
          })
        | null;
    values: MasterValues;
    options: MasterOptions;
}

export interface ContributionRow {
    id: string;
    barcode: string;
    name: string;
    size: string | null;
    sizeValue: string;
    sizeUnit: string;
    packQty: string;
    seen: number;
    status: 'pending' | 'approved' | 'rejected';
    statusLabel: string;
    firstSeenAt: string | null;
    lastSeenAt: string | null;
    reviewedAt: string | null;
    masterProductId: string | null;
}

export interface ContributionProps {
    contributions: Paginated<ContributionRow>;
    filters: { status: string };
    counts: (Option & { count: number })[];
    options: MasterOptions;
}

export interface ImportRow {
    id: string;
    fileName: string;
    sourceRef: string | null;
    status: 'uploaded' | 'queued' | 'running' | 'completed' | 'failed';
    statusLabel: string;
    processed: number;
    created: number;
    updated: number;
    unchanged: number;
    failed: number;
    errors: { row: number; messages: string[] }[];
    by: string | null;
    createdAt: string | null;
    finishedAt: string | null;
}

export interface ImportProps {
    imports: ImportRow[];
    columns: { field: string; names: string[] }[];
}

export const SOURCE_TONES = { starter: 'warning', import: 'info', contribution: 'violet', admin: 'neutral' } as const;
