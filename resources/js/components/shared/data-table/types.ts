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
