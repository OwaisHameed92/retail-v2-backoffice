/** Matches App\Domain\Accounts\Export\ExportPages (gap #8). Money is a 2 dp string. */
import { type AccountsPageProps, type Option, type RefundFixSummary } from './types';

export type ExportTargetKey = 'xero' | 'quickbooks' | 'sage50' | 'sageAccounting';

export interface ExportLine {
    ourCode: string;
    name: string;
    theirCode: string;
    mapped: boolean;
    vatCode: string | null;
    taxCode: string;
    debit: string;
    credit: string;
}

export interface ExportJournal {
    ref: string;
    date: string;
    from: string;
    to: string;
    shop: string;
    narration: string;
    lines: ExportLine[];
    debits: string;
    credits: string;
}

export interface ExportPreviewProps extends AccountsPageProps {
    target: ExportTargetKey;
    grouping: 'daily' | 'period';
    targets: Option[];
    importHelp: string;
    journals: ExportJournal[];
    more: number;
    totals: { debits: string; credits: string; journals: number; lines: number };
    unmapped: { code: string; name: string }[];
    sample: { header: string[]; rows: string[][] };
    refundFix: RefundFixSummary;
}

export interface MappingAccountRow {
    code: string;
    name: string;
    type: string;
    default: string | null;
    theirs: string;
}

export interface MappingVatRow {
    code: string;
    name: string;
    defaultSales: string;
    defaultPurchases: string;
    sales: string;
    purchases: string;
}

export interface ExportMappingsProps extends Pick<AccountsPageProps, 'filters'> {
    target: ExportTargetKey;
    targets: Option[];
    accounts: MappingAccountRow[];
    vat: MappingVatRow[];
    canEdit: boolean;
}
