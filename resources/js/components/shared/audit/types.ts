/** Matches App\Domain\Audit\Support\AuditPresenter::rows(). */
export interface AuditEntry {
    id: string;
    at: string | null;
    action: string;
    actionLabel: string;
    actor: { type: 'admin' | 'user' | 'staff' | 'system' | 'other'; id: string | null; name: string; detail: string | null };
    company: { id: string; name: string } | null;
    subject: { type: string; label: string; id: string | null } | null;
    changes: { field: string; label: string; before: string | null; after: string | null }[];
    meta: { key: string; label: string; value: string | null }[];
    ip: string | null;
    userAgent: string | null;
}

export interface AuditFilterValues {
    actor: string | null;
    company: string | null;
    action: string | null;
    subjectType: string | null;
    subjectId: string | null;
    from: string | null;
    to: string | null;
    search: string | null;
}

export interface AuditOption {
    value: string;
    label: string;
}

/** Matches App\Domain\Audit\Queries\AuditLogList::for(). */
export interface AuditLogProps {
    entries: { data: AuditEntry[]; older: string | null; newer: string | null; perPage: number };
    filters: AuditFilterValues;
    options: {
        actions: (AuditOption & { group: boolean })[];
        subjectTypes: AuditOption[];
        actors: AuditOption[];
        company: { id: string; name: string } | null;
    };
}

const timeFormat = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/London',
});

const secondsFormat = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZone: 'Europe/London',
});

/** "24 Sept 2026, 09:41" (Europe/London). */
export function formatAuditTime(iso: string | null, withSeconds = false): string {
    if (!iso) {
        return '—';
    }

    return (withSeconds ? secondsFormat : timeFormat).format(new Date(iso));
}
