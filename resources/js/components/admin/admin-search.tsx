import { LicenceStatusBadge } from '@/components/admin/licences/licence-status-badge';
import { type LicenceStatus } from '@/components/admin/licences/types';
import { StatusBadge } from '@/components/shared/status-badge';
import { SearchTrigger } from '@/components/shell/topbar';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ArrowRight, Building2, CornerDownLeft, KeyRound, LoaderCircle, Search } from 'lucide-react';
import { type KeyboardEvent, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';

interface TenantHit {
    id: string;
    name: string;
    status: string;
    detail: string | null;
    url: string;
}

interface LicenceHit {
    id: string;
    maskedKey: string;
    status: LicenceStatus;
    businessName: string;
    tillName: string;
    branchName: string;
    deviceName: string | null;
    url: string;
}

interface SearchReply {
    query: string;
    tenants: TenantHit[];
    licences: LicenceHit[];
}

type Item = { kind: 'tenant'; hit: TenantHit } | { kind: 'licence'; hit: LicenceHit } | { kind: 'all'; url: string };

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

/**
 * Admin top-bar search as a command palette (⌘K / Ctrl K, or "/"): tenants by name or owner email, licences by
 * key ending, full key, PC name or id. Arrow keys move, Enter opens.
 */
export function AdminSearch() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [reply, setReply] = useState<SearchReply | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [active, setActive] = useState(0);
    const listId = useId();
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const onKey = (event: globalThis.KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);

            if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                setOpen((value) => !value);
            } else if (event.key === '/' && !typing) {
                event.preventDefault();
                setOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const term = query.trim();

    useEffect(() => {
        if (term.length < 2) {
            setReply(null);
            setError(null);
            setLoading(false);

            return;
        }
        const controller = new AbortController();
        setLoading(true);
        const timer = window.setTimeout(async () => {
            try {
                const result = await sendJson<SearchReply>(
                    'GET',
                    `${route('admin.search')}?q=${encodeURIComponent(term)}`,
                    undefined,
                    controller.signal,
                );
                setReply(result.ok ? result.data : null);
                setError(result.ok ? null : result.message);
                setActive(0);
                setLoading(false);
            } catch {
                // Aborted by a newer search.
            }
        }, 180);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [term]);

    const items: Item[] = useMemo(() => {
        if (!reply) {
            return [];
        }

        return [
            ...reply.tenants.map((hit) => ({ kind: 'tenant' as const, hit })),
            ...reply.licences.map((hit) => ({ kind: 'licence' as const, hit })),
            ...(reply.licences.length > 0
                ? [{ kind: 'all' as const, url: `${route('admin.licences.index')}?search=${encodeURIComponent(term)}` }]
                : []),
        ];
    }, [reply, term]);

    const close = useCallback(() => {
        setOpen(false);
        setQuery('');
        setReply(null);
    }, []);

    const go = (item: Item) => {
        close();
        router.visit(item.kind === 'all' ? item.url : item.hit.url);
    };

    const onInputKey = (event: KeyboardEvent<HTMLInputElement>) => {
        if (items.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => (index + 1) % items.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => (index - 1 + items.length) % items.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(items[active] ?? items[0]);
        }
    };

    useEffect(() => {
        document.getElementById(`${listId}-${active}`)?.scrollIntoView({ block: 'nearest' });
    }, [active, listId]);

    const optionClass = (index: number) =>
        cn(
            'flex w-full cursor-pointer items-center gap-3 rounded-md px-2.5 py-2 text-left text-sm',
            index === active ? 'bg-accent text-accent-foreground' : 'hover:bg-muted',
        );

    let index = -1;

    return (
        <>
            <SearchTrigger
                placeholder="Search tenants, businesses, licence keys, or anything…"
                onClick={() => setOpen(true)}
                aria-haspopup="dialog"
                aria-keyshortcuts={isMac ? 'Meta+K' : 'Control+K'}
            />

            <Dialog open={open} onOpenChange={(value) => (value ? setOpen(true) : close())}>
                <DialogContent
                    className="top-[15%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl [&>button]:hidden"
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();
                        inputRef.current?.focus();
                    }}
                >
                    <DialogTitle className="sr-only">Search</DialogTitle>
                    <DialogDescription className="sr-only">
                        Search tenants by name or owner email, and licences by key ending, full key or PC.
                    </DialogDescription>
                    <div className="flex items-center gap-2 border-b px-3">
                        <Search className="text-muted-foreground size-4 shrink-0" aria-hidden />
                        <input
                            ref={inputRef}
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={onInputKey}
                            placeholder="Search tenant, licence key, device ID"
                            className="placeholder:text-muted-foreground h-12 flex-1 bg-transparent text-sm outline-none"
                            role="combobox"
                            aria-expanded={items.length > 0}
                            aria-controls={listId}
                            aria-activedescendant={items.length > 0 ? `${listId}-${active}` : undefined}
                            aria-label="Search"
                            autoComplete="off"
                            spellCheck={false}
                        />
                        {loading && <LoaderCircle className="text-muted-foreground size-4 animate-spin" aria-hidden />}
                        <kbd className="bg-muted text-muted-foreground rounded border px-1.5 text-[11px]">Esc</kbd>
                    </div>

                    <div id={listId} role="listbox" aria-label="Results" className="max-h-[60vh] overflow-y-auto p-2">
                        {term.length < 2 && (
                            <p className="text-muted-foreground px-2 py-6 text-center text-sm">
                                Type a business name, owner email, the last 4 characters of a key, a full key or a PC name.
                            </p>
                        )}
                        {term.length >= 2 && error && <p className="text-destructive px-2 py-6 text-center text-sm">{error}</p>}
                        {term.length >= 2 && !loading && !error && reply && items.length === 0 && (
                            <p className="text-muted-foreground px-2 py-6 text-center text-sm">No tenants or licences match “{term}”.</p>
                        )}

                        {reply && reply.tenants.length > 0 && (
                            <div role="group" aria-label="Tenants" className="mb-1">
                                <p className="text-muted-foreground px-2 pt-1 pb-1.5 text-xs font-medium">Tenants</p>
                                {reply.tenants.map((hit) => {
                                    index++;
                                    const i = index;

                                    return (
                                        <div
                                            key={hit.id}
                                            id={`${listId}-${i}`}
                                            role="option"
                                            aria-selected={i === active}
                                            className={optionClass(i)}
                                            onMouseMove={() => setActive(i)}
                                            onClick={() => go({ kind: 'tenant', hit })}
                                        >
                                            <Building2 className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate font-medium">{hit.name}</span>
                                                {hit.detail && <span className="text-muted-foreground block truncate text-xs">{hit.detail}</span>}
                                            </span>
                                            <StatusBadge status={hit.status} />
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        {reply && reply.licences.length > 0 && (
                            <div role="group" aria-label="Licences">
                                <p className="text-muted-foreground px-2 pt-1 pb-1.5 text-xs font-medium">Licences</p>
                                {reply.licences.map((hit) => {
                                    index++;
                                    const i = index;

                                    return (
                                        <div
                                            key={hit.id}
                                            id={`${listId}-${i}`}
                                            role="option"
                                            aria-selected={i === active}
                                            className={optionClass(i)}
                                            onMouseMove={() => setActive(i)}
                                            onClick={() => go({ kind: 'licence', hit })}
                                        >
                                            <KeyRound className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate font-mono text-xs">{hit.maskedKey}</span>
                                                <span className="text-muted-foreground block truncate text-xs">
                                                    {hit.businessName} · {hit.tillName}, {hit.branchName}
                                                    {hit.deviceName && ` · ${hit.deviceName}`}
                                                </span>
                                            </span>
                                            <LicenceStatusBadge status={hit.status} />
                                        </div>
                                    );
                                })}
                                {(() => {
                                    index++;
                                    const i = index;

                                    return (
                                        <div
                                            id={`${listId}-${i}`}
                                            role="option"
                                            aria-selected={i === active}
                                            className={cn(optionClass(i), 'text-primary')}
                                            onMouseMove={() => setActive(i)}
                                            onClick={() =>
                                                go({ kind: 'all', url: `${route('admin.licences.index')}?search=${encodeURIComponent(term)}` })
                                            }
                                        >
                                            <ArrowRight className="size-4 shrink-0" aria-hidden />
                                            <span className="flex-1">See all matching licences</span>
                                        </div>
                                    );
                                })()}
                            </div>
                        )}
                    </div>

                    <div className="text-muted-foreground hidden items-center gap-4 border-t px-3 py-2 text-xs sm:flex">
                        <span className="inline-flex items-center gap-1">
                            <kbd className="bg-muted rounded border px-1">↑</kbd>
                            <kbd className="bg-muted rounded border px-1">↓</kbd> to move
                        </span>
                        <span className="inline-flex items-center gap-1">
                            <CornerDownLeft className="size-3" aria-hidden /> to open
                        </span>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
