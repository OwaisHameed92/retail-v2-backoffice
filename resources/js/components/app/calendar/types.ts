/** Matches App\Domain\Calendar\Queries\* and CalendarController (module 5.9). Days are "Y-m-d" shop dates (the profile's time zone); times "HH:MM". */

export interface CalendarFilters {
    shop: string | null;
    when: 'upcoming' | 'past' | 'all';
    today: string;
    shopLocked: boolean;
}

export interface ShopOption {
    value: string;
    label: string;
}

export interface WeekDay {
    weekday: number;
    name: string;
    closed: boolean;
    opens: string | null;
    closes: string | null;
}

export interface SpecialDay {
    id: string;
    date: string;
    shop: string | null;
    event: string | null;
    closed: boolean;
    opens: string | null;
    closes: string | null;
    licensedOpens: string | null;
    licensedCloses: string | null;
    notes: string | null;
}

export interface ShopHours {
    id: string;
    name: string;
    days: WeekDay[] | null;
    tillText: string | null;
    tillTextMatches: boolean;
    specialDays: SpecialDay[];
}

export interface HoursProps {
    shops: ShopHours[];
    defaults: { opens: string; closes: string };
    /** The one `shop.trading_hours` line every till shows (the first shop's week); `differs` when shops' weeks differ. */
    businessLine: { text: string | null; shopId: string | null; shopName: string | null; differs: boolean; max: number };
    filters: CalendarFilters;
}

export interface ListProps {
    filters: CalendarFilters;
    shops: ShopOption[];
    total: number;
    limit: number;
}

export interface SpecialDaysProps extends ListProps {
    days: SpecialDay[];
}

export type EventKind = 'fixed' | 'moveable' | 'bankHoliday' | 'schoolHoliday' | 'local';
export type EventStatus = 'upcoming' | 'onNow' | 'past';

export interface SeasonalEventRow {
    id: string;
    name: string;
    kind: EventKind | null;
    startsOn: string;
    endsOn: string;
    days: number;
    nation: string | null;
    notes: string | null;
    isActive: boolean;
    status: EventStatus;
    shop: string | null;
    uplifts?: number;
}

export interface EventsProps extends ListProps {
    events: SeasonalEventRow[];
}

export interface Pair<T> {
    current: T | null;
    previous: T | null;
    change: string | null;
}

export interface EventProps {
    event: SeasonalEventRow;
    everyShop: boolean;
    canCompareEveryShop: boolean;
    basis: 'lastYearEvent' | 'sameDates';
    lastYear: { name: string | null; from: string; to: string; eventTo: string };
    thisYear: { from: string; to: string };
    daysCompared: number;
    totals: { gross: Pair<string>; net: Pair<string>; transactions: Pair<number>; basket: Pair<string> };
    days: { day: number; date: string; lastYearDate: string; gross: string | null; lastYearGross: string }[];
    departments: {
        id: string | null;
        name: string;
        net: string;
        lastYearNet: string;
        change: string | null;
        tillUplift: string | null;
        learned: boolean;
    }[];
}
