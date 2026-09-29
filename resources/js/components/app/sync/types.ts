import { type Paginated } from '@/components/shared/data-table';

/** App\Domain\TillData\Sync\Enums\ConflictKind */
export type ConflictKind = 'hubEditNewer' | 'hubVersionNewer' | 'branchEditNewer' | 'immutableChange' | 'tenancyDelete';

/** App\Domain\TillData\Sync\Enums\ConflictResolution */
export type ConflictResolution = 'keepPortal' | 'useTill' | 'acknowledged';

export interface Option {
    value: string;
    label: string;
}

/** SyncConflictList::row() */
export interface ConflictRow {
    id: string;
    entity: string;
    entityLabel: string;
    entityId: string;
    subject: string | null;
    kind: ConflictKind;
    kindLabel: string;
    branch: string | null;
    detail: string | null;
    status: 'open' | 'resolved';
    resolution: ConflictResolution | null;
    resolutionLabel: string | null;
    incomingAt: string | null;
    receivedAt: string | null;
    resolvedAt: string | null;
}

/** A till's own clash (its SyncConflict row). */
export interface ClashRow {
    id: string;
    entity: string;
    entityLabel: string;
    entityId: string;
    subject: string | null;
    branch: string | null;
    detail: string;
    resolution: string;
    resolutionLabel: string;
    hasHubChange: boolean;
    detectedAt: string;
    resolvedAt: string | null;
}

/** SyncConflictList::for() */
export interface ConflictIndexProps {
    tab: 'portal' | 'shop';
    search: string | null;
    counts: { open: number; resolved: number; shopPending: number };
    stats: { hubRows: number; historic: number; oldestOpenAt: string | null };
    options: { kinds: Option[]; branches: Option[]; resolutions: Option[] };
    filters: { status?: 'open' | 'resolved' | 'all'; kind?: string | null; branch?: string | null; resolution?: string };
    conflicts?: Paginated<ConflictRow>;
    clashes?: Paginated<ClashRow>;
}

/** One member of the row, the portal's value against the other side's. */
export interface FieldComparison {
    key: string;
    label: string;
    portal: string | null;
    till: string | null;
    changed: boolean;
}

/** SyncConflictDetail::conflict() */
export interface ConflictDetailProps {
    conflict: ConflictRow & {
        localVersion: number | null;
        incomingVersion: number;
        incomingSeq: number | null;
        resolutionNote: string | null;
        resolvedBy: string | null;
        rowExists: boolean;
    };
    fields: FieldComparison[];
    resolutions: { value: ConflictResolution; label: string }[];
}

/** SyncConflictDetail::clash() */
export interface ClashDetailProps {
    clash: Omit<ClashRow, 'hasHubChange'> & {
        ownership: string | null;
        hubVersion: number;
        branchVersion: number;
    };
    hubChange: { op: string | null; version: number | null; at: string | null } | null;
    fields: FieldComparison[];
}
