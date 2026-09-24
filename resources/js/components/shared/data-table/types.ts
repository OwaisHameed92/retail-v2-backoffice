import { type RowData } from '@tanstack/react-table';

/** Matches `meta` from App\Domain\Shared\Support\TableQuery::paginate(). */
export interface TableMeta {
    page: number;
    perPage: number;
    total: number;
    lastPage?: number;
    search?: string | null;
    sort?: string | null;
    direction?: 'asc' | 'desc';
}

/** Matches the whole array returned by TableQuery::paginate(). */
export interface Paginated<T> {
    data: T[];
    meta: TableMeta;
}

export type TableParamValue = string | number | boolean | null | undefined;

/** Query string params the table sends. Filters can add their own keys. */
export interface TableParams {
    page?: number;
    perPage?: number;
    search?: string;
    sort?: string;
    direction?: 'asc' | 'desc';
    [filter: string]: TableParamValue;
}

export const PER_PAGE_OPTIONS = [10, 25, 50, 100] as const;

/**
 * Where a column goes in the phone card layout. Defaults: first column → title, `status` → aside,
 * `actions` → actions, everything else → field (the first four fields are shown).
 */
export type MobileSlot = 'title' | 'aside' | 'field' | 'actions' | 'hidden';

declare module '@tanstack/react-table' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface ColumnMeta<TData extends RowData, TValue> {
        /** Right-align numbers and money (header and cells). */
        align?: 'left' | 'right' | 'center';
        headerClassName?: string;
        cellClassName?: string;
        /** Phone card placement. */
        mobile?: MobileSlot;
        /** Label for the phone card field when `header` is not a plain string. */
        label?: string;
    }
}
