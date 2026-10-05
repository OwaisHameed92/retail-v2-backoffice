/** Matches App\Domain\Compliance\Queries\* and ComplianceController (module 5.7). Instants are ISO UTC; days `Y-m-d`. */
import { type TableMeta } from '@/components/shared/data-table';

export interface Option {
    value: string;
    label: string;
}

export interface ComplianceFiltersState {
    from: string;
    to: string;
    shop: string | null;
    staff: string | null;
    rule: string | null;
    type: string | null;
    status: string | null;
    shopLocked: boolean;
}

export interface CompliancePageProps {
    filters: ComplianceFiltersState;
    options: { shops: Option[]; staff: Option[] };
    canManage: boolean;
}

export interface Page<T> {
    data: T[];
    meta: TableMeta;
}

export type ExpiryStatus = 'valid' | 'expiring' | 'expired' | 'none';

export interface AttentionRow {
    id: string;
    label: string;
    tone: 'danger' | 'warning' | 'info';
    text: string;
    href: string;
}

export interface OverviewProps extends CompliancePageProps {
    attention: { items: AttentionRow[]; total: number };
    figures: {
        refusals: number;
        refusalRate: string | null;
        incidents: number;
        licencesDue: number;
        trainingDue: number;
        missedChecks: number;
        openRecalls: number;
    };
    windows: { week: { from: string; to: string }; month: { from: string; to: string } };
}

export interface BreakdownRow {
    key: string | null;
    label: string;
    checks: number;
    refusals: number;
    rate: string | null;
}

export interface RefusalRow {
    id: string;
    at: string | null;
    shop: string | null;
    till: string | null;
    staff: string | null;
    product: string | null;
    rule: string | null;
    note: string | null;
}

export interface AgeChecksProps extends CompliancePageProps {
    summary: { checks: number; refusals: number; rate: string | null };
    byShop: BreakdownRow[];
    byStaff: BreakdownRow[];
    byRule: BreakdownRow[];
    byProduct: BreakdownRow[];
    refusalLog: Page<RefusalRow>;
    rules: Option[];
}

export interface IncidentRow {
    id: string;
    occurredAt: string | null;
    category: string | null;
    description: string | null;
    shop: string | null;
    reportedBy: string | null;
    police: string | null;
    insurer: string | null;
}

export interface IncidentsProps extends CompliancePageProps {
    incidents: Page<IncidentRow>;
    categories: (Option & { count: number })[];
    summary: { total: number; police: number };
}

export interface IncidentProps {
    incident: IncidentRow & { recordedAt: string | null };
}

export interface TrainingRow {
    id: string;
    staff: string | null;
    topic: string | null;
    trainedOn: string | null;
    expiresOn: string | null;
    status: ExpiryStatus;
    daysLeft: number | null;
    trainer: string | null;
    notes: string | null;
    shop: string | null;
}

export interface TrainingProps extends CompliancePageProps {
    records: Page<TrainingRow>;
    summary: { total: number; staff: number; expiring: number; expired: number };
    topics: Option[];
    soonDays: number;
}

export interface LicenceRow {
    id: string;
    type: string | null;
    number: string | null;
    holder: string | null;
    issuedOn: string | null;
    expiresOn: string | null;
    status: ExpiryStatus;
    daysLeft: number | null;
    notes: string | null;
    shop: string | null;
}

export interface LicencesProps extends CompliancePageProps {
    licences: Page<LicenceRow>;
    summary: { total: number; expiring: number; expired: number };
    types: Option[];
    soonDays: number;
}

export type Schedule = 'daily' | 'weekly' | 'perShift' | null;

export interface DefinitionRow {
    id: string;
    name: string;
    category: string | null;
    schedule: Schedule;
    active: boolean;
    shop: string | null;
    due: number;
    done: number;
    missed: number;
    dueNow: boolean;
    lastDoneAt: string | null;
}

export interface MissedRow {
    definitionId: string;
    name: string;
    shop: string | null;
    schedule: Schedule;
    /** `Y-m-d` (daily), `week:Y-m-d` (the Monday) or `shift:<ISO>` (when the shift opened). */
    period: string;
    start: string | null;
    end: string | null;
}

export interface DiaryRecordRow {
    id: string;
    recordedAt: string | null;
    check: string | null;
    shop: string | null;
    staff: string | null;
    value: string | null;
    passed: boolean;
    note: string | null;
}

export interface DiaryProps extends CompliancePageProps {
    definitions: DefinitionRow[];
    missed: MissedRow[];
    summary: { definitions: number; due: number; done: number; missed: number; failed: number };
    records: Page<DiaryRecordRow>;
}

export interface RecallRow {
    id: string;
    reference: string | null;
    product: string | null;
    batchCode: string | null;
    expiryFrom: string | null;
    expiryTo: string | null;
    source: string | null;
    reason: string | null;
    status: 'open' | 'closed' | null;
    raisedAt: string | null;
    onHand?: string | null;
}

export interface RecallFormProps {
    suppliers: Option[];
    productResults?: Option[];
}

export interface RecallsProps extends CompliancePageProps, RecallFormProps {
    recalls: Page<RecallRow>;
    summary: { open: number; closed: number };
}

export interface RecallDetail extends RecallRow {
    productId: string | null;
    supplierId: string | null;
    supplier: string | null;
    returnedQty: string;
    closedAt: string | null;
    note: string | null;
    fromPortal: boolean;
}

export interface RecallProps extends CompliancePageProps, RecallFormProps {
    recall: RecallDetail;
    stock: { shop: string; onHand: string | null; batchQty: string | null; batches: number; returned: string | null }[];
    matchesBatches: boolean;
}

export interface ExceptionStaffRow {
    key: string | null;
    staff: string;
    noSales: number;
    voidedLines: number;
    other: number;
    amount: string;
    total: number;
}

export interface ExceptionLogRow {
    id: string;
    at: string | null;
    type: string;
    staff: string | null;
    shop: string | null;
    till: string | null;
    amount: string | null;
    detail: string | null;
}

export interface ExceptionsProps extends CompliancePageProps {
    staff: ExceptionStaffRow[];
    types: (Option & { count: number; amount: string })[];
    summary: { noSales: number; voidedLines: number; other: number; amount: string };
    log: Page<ExceptionLogRow> & { kind: 'exceptions' | 'voids' };
}
