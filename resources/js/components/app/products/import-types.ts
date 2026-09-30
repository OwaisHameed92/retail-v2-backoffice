export type ImportStatus = 'uploaded' | 'queued' | 'running' | 'completed' | 'failed';

/** ImportDetail::row() */
export interface ImportRow {
    id: string;
    fileName: string;
    status: ImportStatus;
    statusLabel: string;
    totalRows: number;
    validRows: number;
    errorRows: number;
    processedRows: number;
    created: number;
    updated: number;
    unchanged: number;
    failed: number;
    by: string | null;
    createdAt: string | null;
    previewedAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
}

export interface ImportField {
    value: string;
    label: string;
    help: string;
}

export interface SampleRow {
    line: number;
    barcode: string | null;
    sku: string | null;
    name: string | null;
    sellPrice: string | null;
    department: string | null;
    category: string | null;
    action: 'create' | 'update' | 'skip';
    errors: string[];
}

export interface ImportDetail extends ImportRow {
    headers: string[];
    mapping: Record<string, number>;
    preview: { new: number; update: number; sample: SampleRow[] } | null;
    errors: { row: number; messages: string[] }[];
    errorsTruncated: boolean;
}

export const IMPORT_TONES = { uploaded: 'info', queued: 'neutral', running: 'info', completed: 'success', failed: 'danger' } as const;
