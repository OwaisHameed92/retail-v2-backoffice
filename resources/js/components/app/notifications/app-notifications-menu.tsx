import { EmptyState } from '@/components/shared/empty-state';
import { chromeButton } from '@/components/shell/topbar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { sendJson } from '@/lib/http';
import { relativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Bell, CircleAlert, Settings2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface NotificationItem {
    id: string;
    type: string;
    tone: 'danger' | 'warning' | 'success' | 'info';
    title: string;
    body: string | null;
    url: string | null;
    read: boolean;
    createdAt: string | null;
}

interface Feed {
    unread: number;
    items: NotificationItem[];
}

const toneDot: Record<NotificationItem['tone'], string> = {
    danger: 'bg-danger',
    warning: 'bg-warning',
    success: 'bg-success',
    info: 'bg-info',
};

/** The last feed, shared across page visits so the bell does not flash empty; refreshed after STALE_MS. */
let cached: { feed: Feed; at: number } | null = null;
const STALE_MS = 60_000;

/**
 * Business portal notifications bell (module 7.8): the user's alerts in this business (till offline, sync, the
 * daily summary), newest first, with an unread dot. Opening a notification marks it read and goes to its screen.
 */
export function AppNotificationsMenu() {
    const [feed, setFeed] = useState<Feed | null>(cached?.feed ?? null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async (force = false) => {
        if (!force && cached && Date.now() - cached.at < STALE_MS) {
            setFeed(cached.feed);
            return;
        }
        setLoading(true);
        const result = await sendJson<Feed>('GET', route('app.notifications.index'));
        setLoading(false);
        if (result.ok && result.data) {
            cached = { feed: result.data, at: Date.now() };
            setFeed(result.data);
            setError(null);
        } else {
            setError(result.message);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    const markRead = async (id?: string) => {
        const result = await sendJson<Feed>('POST', route('app.notifications.read'), { id: id ?? null });
        if (result.ok && result.data) {
            cached = { feed: result.data, at: Date.now() };
            setFeed(result.data);
        }
    };

    const open = (item: NotificationItem) => {
        if (!item.read) {
            void markRead(item.id);
        }
        if (item.url) {
            router.visit(item.url);
        }
    };

    const unread = feed?.unread ?? 0;

    return (
        <DropdownMenu onOpenChange={(isOpen) => isOpen && void load(true)}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className={cn('relative size-10', chromeButton)}
                    aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
                >
                    <Bell className="size-5!" strokeWidth={1.75} />
                    {unread > 0 && <span className="bg-primary ring-chrome absolute top-2 right-2.5 size-2 rounded-full ring-2" aria-hidden />}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[min(22rem,calc(100vw-2rem))] p-0">
                <div className="flex items-center justify-between gap-2 border-b px-4 py-3">
                    <p className="text-sm font-semibold">Notifications</p>
                    {unread > 0 && (
                        <button type="button" onClick={() => void markRead()} className="text-primary text-xs font-medium hover:underline">
                            Mark all as read
                        </button>
                    )}
                </div>

                <div className="max-h-[min(26rem,60vh)] overflow-y-auto">
                    {feed === null && loading && (
                        <div className="space-y-3 p-4" aria-label="Loading notifications">
                            {[0, 1, 2].map((row) => (
                                <Skeleton key={row} className="h-10 w-full" />
                            ))}
                        </div>
                    )}

                    {feed === null && !loading && error && (
                        <div className="flex items-start gap-2 p-4 text-sm">
                            <CircleAlert className="text-destructive mt-0.5 size-4 shrink-0" aria-hidden />
                            <span className="text-muted-foreground">{error}</span>
                        </div>
                    )}

                    {feed !== null && feed.items.length === 0 && (
                        <EmptyState
                            icon={Bell}
                            title="You are all caught up"
                            body="Alerts about tills, sync, stock, cash and compliance show here."
                            size="sm"
                        />
                    )}

                    {feed !== null && feed.items.length > 0 && (
                        <ul className="divide-y">
                            {feed.items.map((item) => (
                                <li key={item.id}>
                                    <button
                                        type="button"
                                        onClick={() => open(item)}
                                        className={cn(
                                            'hover:bg-muted/60 focus-visible:bg-muted/60 flex w-full items-start gap-3 px-4 py-3 text-left outline-none',
                                            !item.read && 'bg-primary/5',
                                        )}
                                    >
                                        <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', toneDot[item.tone])} aria-hidden />
                                        <span className="min-w-0 flex-1">
                                            <span className={cn('block text-sm', item.read ? 'text-foreground' : 'font-semibold')}>{item.title}</span>
                                            {item.body && (
                                                <span className="text-muted-foreground mt-0.5 line-clamp-2 block text-xs">{item.body}</span>
                                            )}
                                            {item.createdAt && (
                                                <time dateTime={item.createdAt} className="text-muted-foreground mt-1 block text-[11px]">
                                                    {relativeTime(item.createdAt)}
                                                </time>
                                            )}
                                        </span>
                                        {!item.read && <span className="sr-only">Unread</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="border-t px-2 py-1.5">
                    <Link
                        href={route('app.notifications.settings')}
                        className="text-muted-foreground hover:text-foreground hover:bg-muted flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                    >
                        <Settings2 className="size-4" aria-hidden />
                        Choose your alert emails
                    </Link>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
