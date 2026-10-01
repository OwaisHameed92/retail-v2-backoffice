/** Matches App\Domain\StaffTime\Queries\* and StaffTimeController (module 5.6). Minutes are whole numbers; instants ISO UTC. */
import { type TableMeta } from '@/components/shared/data-table';

export interface Option {
    value: string;
    label: string;
}

export interface TimeFiltersState {
    from: string;
    to: string;
    shop: string | null;
    till: string | null;
    person: string | null;
    rounding: number;
    group: 'week' | 'period';
    problems: boolean;
    shopLocked: boolean;
}

export interface TimePageProps {
    filters: TimeFiltersState;
    options: { shops: Option[]; tills: Option[]; people: Option[] };
}

export interface Page<T> {
    data: T[];
    meta: TableMeta;
}

export type ShiftStatus = 'complete' | 'open' | 'missingOut' | 'missingIn';
export type ShiftFlag = 'breakNotEnded' | 'overMaxShift';

export interface ClockRow {
    id: string;
    personId: string;
    person: string;
    shop: string | null;
    till: string | null;
    day: string;
    clockIn: string | null;
    clockOut: string | null;
    breaks: { start: string | null; end: string | null }[];
    breakMinutes: number;
    workedMinutes: number;
    paidMinutes: number;
    status: ShiftStatus;
    flags: ShiftFlag[];
    planned: string | null;
}

export interface ClockProps extends TimePageProps {
    shifts: Page<ClockRow>;
    summary: { shifts: number; workedMinutes: number; paidMinutes: number; missing: number; onShift: number; problems: number; openLimitHours: number };
}

export interface TimesheetRow {
    id: string;
    personId: string;
    person: string;
    shopId: string | null;
    shop: string | null;
    weekStart: string | null;
    shifts: number;
    workedMinutes: number;
    breakMinutes: number;
    paidMinutes: number;
    /** Over 8 hours in a day (the till's rule), shown only. */
    overtimeMinutes: number;
    plannedMinutes: number;
    differenceMinutes: number;
    /** 12.07% of the hours worked: an estimate, as the till's timesheet screen shows. */
    holidayMinutes: number;
    missing: number;
    rate: string | null;
    wage: string | null;
    approval: { approvedAt: string | null; approvedBy: string | null; totalHours: string; overtimeHours: string } | null;
}

export interface WageBand {
    id: string;
    label: string;
    ageFrom: number;
    ageTo: number | null;
    effectiveFrom: string;
    rate: string | null;
    isPlaceholder: boolean;
}

export interface TimesheetsProps extends TimePageProps {
    timesheets: Page<TimesheetRow>;
    summary: {
        people: number;
        workedMinutes: number;
        paidMinutes: number;
        overtimeMinutes: number;
        holidayMinutes: number;
        plannedMinutes: number;
        missing: number;
        wages: string;
        withoutRate: number;
    };
    wageBands: WageBand[];
}

export interface RotaShiftCell {
    id: string;
    start: string | null;
    end: string | null;
    breakMinutes: number;
    minutes: number;
    readable: boolean;
    isPublished: boolean;
    note: string;
    shop: string | null;
}

export interface RotaDay {
    planned: RotaShiftCell[];
    plannedMinutes: number;
    workedMinutes: number;
    missing: number;
    onShift: boolean;
}

export interface RotaRow {
    personId: string;
    person: string;
    days: Record<string, RotaDay | null>;
    plannedMinutes: number;
    workedMinutes: number;
    differenceMinutes: number;
}

export interface RotaProps extends TimePageProps {
    week: string;
    days: string[];
    rows: RotaRow[];
    summary: { people: number; shifts: number; plannedMinutes: number; workedMinutes: number; drafts: number; unreadable: number };
}
