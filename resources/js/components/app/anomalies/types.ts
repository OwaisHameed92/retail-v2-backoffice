import { type TableMeta } from '@/components/shared/data-table';

/** Matches `App\Domain\Anomalies\Queries\AnomalyList` / `AnomalyDetail` (module 6.6). */
export type AnomalySeverity = 'low' | 'medium' | 'high';
export type AnomalyStatus = 'new' | 'acknowledged' | 'dismissed';

export interface AnomalyRow {
    id: string;
    kind: string;
    kindLabel: string;
    severity: AnomalySeverity;
    status: AnomalyStatus;
    title: string;
    summary: string;
    shop: string;
    staffLevel: boolean;
    subjectName: string | null;
    tradingDay: string;
    detectedAt: string;
    occurrences: number;
}

export interface AnomalyFact {
    label: string;
    value: string;
    usual: string | null;
    peers: string | null;
}

export interface AnomalyDetailRow extends AnomalyRow {
    facts: AnomalyFact[];
    links: { label: string; href: string }[];
    periodStart: string;
    periodEnd: string;
    statusReason: string | null;
    statusBy: string | null;
    statusAt: string | null;
    explanation: string | null;
}

export interface AnomalyFiltersState {
    from: string;
    to: string;
    status: 'open' | 'new' | 'acknowledged' | 'dismissed' | 'all';
    severity: AnomalySeverity | null;
    kind: string | null;
    shop: string | null;
    shopLocked: boolean;
}

export interface Option {
    value: string;
    label: string;
}

export interface AnomalyIndexProps {
    anomalies: { data: AnomalyRow[]; meta: TableMeta };
    summary: { new: number; acknowledged: number; dismissed: number; highOpen: number };
    filters: AnomalyFiltersState;
    options: { shops: Option[]; kinds: (Option & { staffLevel: boolean })[]; severities: Option[] };
    seesStaff: boolean;
    canManage: boolean;
}

export interface AnomalyHistoryEntry {
    id: string;
    action: string;
    by: string;
    at: string | null;
    reason: string | null;
}

export interface AnomalyShowProps {
    anomaly: AnomalyDetailRow;
    history: AnomalyHistoryEntry[];
    canManage: boolean;
    canExplain: boolean;
}
