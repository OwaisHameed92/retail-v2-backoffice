/** Matches App\Domain\Cash\Queries\* and CashController (module 5.4). Money is a 2 dp string; instants are ISO UTC. */
import { type TableMeta } from '@/components/shared/data-table';

export interface Option {
    value: string;
    label: string;
}

export interface CashFiltersState {
    from: string;
    to: string;
    shop: string | null;
    till: string | null;
    status: 'open' | 'closed' | null;
    threshold: string | null;
    shopLocked: boolean;
}

export interface CashPageProps {
    filters: CashFiltersState;
    options: { shops: Option[]; tills: Option[] };
}

export interface Page<T> {
    data: T[];
    meta: TableMeta;
}

export interface ShiftRow {
    id: string;
    status: 'open' | 'closed' | null;
    openedAt: string | null;
    closedAt: string | null;
    shop: string | null;
    till: string | null;
    user: string | null;
    closedBy: string | null;
    float: string | null;
    cashExpected: string | null;
    cashCounted: string | null;
    cashVariance: string | null;
    variance: string | null;
    z: number | null;
    zId: string | null;
    warning: boolean;
}

export interface ShiftsProps extends CashPageProps {
    shifts: Page<ShiftRow>;
    summary: { shifts: number; open: number; cashVariance: string; short: number; over: number; waitingCount: number; waitingTotal: string };
}

export interface ZTender {
    paymentTypeId: string | null;
    name: string;
    expected: string | null;
    declared: string | null;
    terminal: string | null;
    variance: string | null;
    exceedsThreshold: boolean;
}

export interface ZTotals {
    tenders: ZTender[];
    variance: string | null;
    warning: boolean;
    alertOver: string | null;
    readable: boolean;
}

export interface ShiftDetailProps {
    shift: {
        id: string;
        status: 'open' | 'closed' | null;
        mode: string | null;
        shop: string | null;
        till: string | null;
        openedAt: string | null;
        closedAt: string | null;
        openedBy: string | null;
        closedBy: string | null;
        drawerOwner: string | null;
        overrideBy: string | null;
        overrideReason: string | null;
        notes: string | null;
        float: string | null;
        variance: string | null;
        salesCount: number;
        salesTotal: string;
    };
    tenders: {
        id: string;
        name: string;
        cash: boolean;
        expected: string | null;
        declared: string | null;
        terminal: string | null;
        variance: string | null;
    }[];
    movements: {
        id: string;
        type: string | null;
        at: string | null;
        amount: string | null;
        reason: string | null;
        note: string | null;
        user: string | null;
        adopted: boolean;
    }[];
    movementTotals: { type: string | null; count: number; total: string }[];
    stages: {
        stage: 'open' | 'close' | 'spot' | null;
        at: string | null;
        user: string | null;
        expected: string | null;
        counted: string;
        variance: string | null;
        lines: { denomination: string | null; count: number; total: string | null }[];
    }[];
    z: { id: string; sequenceNo: number; printedAt: string | null; totals: ZTotals } | null;
}

export interface ZRow {
    id: string;
    sequenceNo: number;
    day: string;
    shop: string | null;
    till: string | null;
    periodStart: string | null;
    periodEnd: string | null;
    printedAt: string | null;
    reprints: number;
    variance: string | null;
    warning: boolean;
    shiftId: string | null;
}

export interface ZReportsProps extends CashPageProps {
    reports: Page<ZRow>;
}

export interface ZReportProps {
    report: Omit<ZRow, 'variance' | 'warning'> & { generatedAt: string | null; printedBy: string | null; totals: ZTotals };
}

export interface BankingRow {
    id: string;
    reference: string | null;
    shop: string | null;
    amount: string | null;
    status: 'prepared' | 'banked' | 'cancelled' | 'inTransit' | null;
    method: 'ownBanking' | 'carrier' | null;
    carrier: string | null;
    sealNumber: string | null;
    preparedAt: string | null;
    preparedBy: string | null;
    collectedAt: string | null;
    collectedBy: string | null;
    bankedAt: string | null;
    bankedBy: string | null;
    bankReference: string | null;
    confirmedAmount: string | null;
    variance: string | null;
    note: string | null;
}

export interface BankingProps extends CashPageProps {
    bankings: Page<BankingRow>;
    summary: { count: number; total: string; waiting: number; variance: string };
}

export interface CountRow {
    id: string;
    day: string;
    shop: string | null;
    countedAt: string | null;
    countedBy: string | null;
    expected: string | null;
    counted: string | null;
    variance: string | null;
    reason: string | null;
    note: string | null;
    denominations: { denomination: string; count: number }[];
}

export interface CountsProps extends CashPageProps {
    counts: Page<CountRow>;
    summary: { count: number; variance: string; short: number; over: number };
}

export type CardFlag = 'matched' | 'difference' | 'notSettled' | 'failed' | 'noTillTotal' | 'mismatched';

export interface CardDayRow {
    key: string;
    day: string;
    registerId: string | null;
    shop: string | null;
    till: string | null;
    tillCard: string | null;
    settled: string | null;
    difference: string | null;
    flag: CardFlag;
    shifts: number;
    settlements: {
        id: string;
        provider: string | null;
        reference: string | null;
        batch: string | null;
        terminal: string | null;
        pos: string | null;
        variance: string | null;
        fees: string | null;
        transactions: number;
        status: 'matched' | 'mismatched' | 'failed' | null;
        message: string | null;
        settledAt: string | null;
        settledBy: string | null;
    }[];
}

export interface CardsProps extends CashPageProps {
    days: Page<CardDayRow>;
    summary: { days: number; flagged: number; tillCard: string; settled: string; difference: string };
}

export interface DayLockRow {
    key: string;
    day: string;
    shop: string;
    status: 'locked' | 'unlocked' | 'open' | 'today';
    lockedAt: string | null;
    lockedBy: string | null;
    unlockedAt: string | null;
    unlockedBy: string | null;
    reason: string | null;
}

export interface DaysProps extends CashPageProps {
    days: Page<DayLockRow>;
    summary: { locked: number; unlocked: number; open: number };
}

export interface AlertRow {
    id: string;
    kind: 'shift' | 'safeCount' | 'banking';
    at: string | null;
    day: string | null;
    shop: string | null;
    till: string | null;
    what: string;
    variance: string;
    shiftVariance: string | null;
    threshold: string;
    tillFlag: boolean;
    shiftId: string | null;
    zId: string | null;
}

export interface AlertsProps extends CashPageProps {
    alerts: Page<AlertRow>;
    summary: {
        total: number;
        short: number;
        shifts: number;
        office: number;
        threshold: string | null;
        defaultThreshold: string;
        shopThresholds: boolean;
    };
}
