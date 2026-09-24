import { EmptyState } from '@/components/shared/empty-state';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Bell, BookOpen, ChevronRight, CircleHelp, Keyboard, LifeBuoy, Search } from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';

export interface TopbarCrumb {
    title: string;
    href?: string;
}

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
export const modKey = isMac ? '⌘' : 'Ctrl';

/**
 * The 56px sticky top bar: sidebar toggle and breadcrumbs on the left, search in the middle, help,
 * notifications and the account menu on the right. Layouts fill the slots.
 */
export function Topbar({
    breadcrumbs,
    search,
    actions,
    className,
}: {
    breadcrumbs?: ReactNode;
    search?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <header
            className={cn(
                'bg-background/85 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b px-3 backdrop-blur-md sm:px-4 lg:px-6',
                className,
            )}
        >
            <SidebarTrigger className="text-muted-foreground hover:text-foreground -ml-1 size-8" />
            <div className="bg-border hidden h-5 w-px sm:block" aria-hidden />
            <div className="flex min-w-0 flex-1 items-center gap-3">
                <div className="hidden min-w-0 shrink md:block">{breadcrumbs}</div>
                {search && <div className="flex min-w-0 flex-1 justify-center md:px-4">{search}</div>}
            </div>
            <div className="flex shrink-0 items-center gap-1">{actions}</div>
        </header>
    );
}

/** Breadcrumb trail for the top bar. The last crumb is the current page. */
export function TopbarBreadcrumbs({ items }: { items: TopbarCrumb[] }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <nav aria-label="Breadcrumb">
            <ol className="text-muted-foreground flex min-w-0 items-center gap-1.5 text-sm">
                {items.map((item, index) => {
                    const last = index === items.length - 1;

                    return (
                        <Fragment key={`${item.title}-${index}`}>
                            <li className={cn('min-w-0 truncate', last ? 'text-foreground font-medium' : 'hidden lg:block')}>
                                {item.href && !last ? (
                                    <Link href={item.href} className="hover:text-foreground transition-colors">
                                        {item.title}
                                    </Link>
                                ) : (
                                    <span aria-current={last ? 'page' : undefined}>{item.title}</span>
                                )}
                            </li>
                            {!last && (
                                <li aria-hidden className="text-muted-foreground/50 hidden lg:block">
                                    <ChevronRight className="size-3.5" />
                                </li>
                            )}
                        </Fragment>
                    );
                })}
            </ol>
        </nav>
    );
}

/**
 * Button that looks like a search field and opens a command palette. `disabled` shows it as "coming soon"
 * (e.g. the tenant AI search).
 */
export function SearchTrigger({
    placeholder,
    onClick,
    disabled = false,
    icon: Icon = Search,
    shortcut = true,
}: {
    placeholder: string;
    onClick?: () => void;
    disabled?: boolean;
    icon?: typeof Search;
    shortcut?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={disabled ? 'Coming soon' : undefined}
            className={cn(
                'border-input bg-card text-muted-foreground flex h-9 w-full max-w-md items-center gap-2 rounded-lg border px-3 text-sm shadow-xs transition-[border-color,box-shadow] duration-150',
                'hover:border-border-strong focus-visible:border-ring focus-visible:ring-ring/20 outline-none focus-visible:ring-[3px]',
                'disabled:hover:border-input dark:bg-background/40 disabled:cursor-default disabled:opacity-70',
            )}
        >
            <Icon className="size-4 shrink-0" aria-hidden />
            <span className="truncate">{placeholder}</span>
            {disabled ? (
                <span className="bg-muted ml-auto hidden rounded px-1.5 text-[11px] font-medium sm:inline">Soon</span>
            ) : (
                shortcut && (
                    <kbd className="bg-muted ml-auto hidden rounded border px-1.5 font-sans text-[11px] font-medium sm:inline">{modKey} K</kbd>
                )
            )}
        </button>
    );
}

const shortcuts: { keys: string[]; label: string }[] = [
    { keys: [modKey, 'K'], label: 'Open search' },
    { keys: ['/'], label: 'Open search (when not typing)' },
    { keys: [modKey, 'B'], label: 'Show or hide the sidebar' },
    { keys: ['↑', '↓'], label: 'Move through search results' },
    { keys: ['Enter'], label: 'Open the highlighted result or row' },
    { keys: ['Esc'], label: 'Close a dialog or menu' },
];

/** "?" menu: help centre and support (soon) and the keyboard shortcuts sheet. */
export function HelpMenu() {
    const [shortcutsOpen, setShortcutsOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="text-muted-foreground hover:text-foreground size-9" aria-label="Help">
                        <CircleHelp className="size-[18px]" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-60">
                    <DropdownMenuLabel>Help</DropdownMenuLabel>
                    <DropdownMenuItem onSelect={() => setShortcutsOpen(true)}>
                        <Keyboard className="text-muted-foreground size-4" aria-hidden />
                        Keyboard shortcuts
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem disabled>
                        <BookOpen className="text-muted-foreground size-4" aria-hidden />
                        Help centre
                        <span className="text-muted-foreground ml-auto text-[11px]">Soon</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem disabled>
                        <LifeBuoy className="text-muted-foreground size-4" aria-hidden />
                        Contact support
                        <span className="text-muted-foreground ml-auto text-[11px]">Soon</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={shortcutsOpen} onOpenChange={setShortcutsOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogTitle>Keyboard shortcuts</DialogTitle>
                    <DialogDescription>Move around the backoffice without the mouse.</DialogDescription>
                    <ul className="divide-y rounded-lg border">
                        {shortcuts.map((shortcut) => (
                            <li key={shortcut.label} className="flex items-center justify-between gap-4 px-3 py-2.5 text-sm">
                                <span>{shortcut.label}</span>
                                <span className="flex shrink-0 gap-1">
                                    {shortcut.keys.map((key) => (
                                        <kbd
                                            key={key}
                                            className="bg-muted text-muted-foreground min-w-6 rounded border px-1.5 py-0.5 text-center font-sans text-xs font-medium"
                                        >
                                            {key}
                                        </kbd>
                                    ))}
                                </span>
                            </li>
                        ))}
                    </ul>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** Bell with an empty inbox until notifications exist. */
export function NotificationsMenu() {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="text-muted-foreground hover:text-foreground size-9" aria-label="Notifications">
                    <Bell className="size-[18px]" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 p-0">
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <p className="text-sm font-semibold">Notifications</p>
                </div>
                <EmptyState icon={Bell} title="You are all caught up" body="Alerts about tills, trials and payments will show here." size="sm" />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
