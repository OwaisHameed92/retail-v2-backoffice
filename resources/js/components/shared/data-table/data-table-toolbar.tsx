import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

interface DataTableToolbarProps {
    search?: string | null;
    onSearch?: (value: string) => void;
    searchPlaceholder?: string;
    /** Milliseconds to wait after typing stops. */
    debounceMs?: number;
    /** Filter controls (selects, toggles) shown after the search box. */
    filters?: ReactNode;
    /** Buttons on the right, e.g. "Export". */
    actions?: ReactNode;
    className?: string;
}

export function DataTableToolbar({
    search,
    onSearch,
    searchPlaceholder = 'Search',
    debounceMs = 300,
    filters,
    actions,
    className,
}: DataTableToolbarProps) {
    const [value, setValue] = useState(search ?? '');
    const lastSent = useRef(search ?? '');

    // Keep in sync when the server reply changes the search (e.g. back button).
    useEffect(() => {
        setValue(search ?? '');
        lastSent.current = search ?? '';
    }, [search]);

    useEffect(() => {
        if (!onSearch || value.trim() === lastSent.current.trim()) {
            return;
        }
        const timer = window.setTimeout(() => {
            lastSent.current = value;
            onSearch(value.trim());
        }, debounceMs);

        return () => window.clearTimeout(timer);
    }, [value, debounceMs, onSearch]);

    if (!onSearch && !filters && !actions) {
        return null;
    }

    return (
        <div className={cn('flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between', className)}>
            <div className="flex flex-1 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                {onSearch && (
                    <div className="relative w-full sm:max-w-xs">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                        <Input
                            type="search"
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                            placeholder={searchPlaceholder}
                            aria-label={searchPlaceholder}
                            className="h-9 pl-8"
                        />
                    </div>
                )}
                {filters}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

export default DataTableToolbar;
