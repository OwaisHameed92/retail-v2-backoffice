import { EmptyState } from '@/components/shared/empty-state';
import { InitialsAvatar } from '@/components/shared/entity-cell';
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
import { cn } from '@/lib/utils';
import { Bell, BookOpen, ChevronDown, CircleHelp, Keyboard, LifeBuoy } from 'lucide-react';
import { useState, type ComponentProps } from 'react';

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
export const modKey = isMac ? '⌘' : 'Ctrl';

/** Ghost button classes for icons and menus on the dark top bar. */
export const chromeButton =
    'text-chrome-muted hover:bg-chrome-hover hover:text-chrome-foreground data-[state=open]:bg-chrome-hover data-[state=open]:text-chrome-foreground focus-visible:ring-sidebar-ring/35';

const shortcuts: { keys: string[]; label: string }[] = [
    { keys: [modKey, 'K'], label: 'Open search' },
    { keys: ['/'], label: 'Open search (when not typing)' },
    { keys: [modKey, 'B'], label: 'Show or hide the sidebar' },
    { keys: ['↑', '↓'], label: 'Move through search results' },
    { keys: ['Enter'], label: 'Open the highlighted result or row' },
    { keys: ['Esc'], label: 'Close a dialog or menu' },
];

/** "Help" menu: help centre and support (soon) and the keyboard shortcuts sheet. */
export function HelpMenu() {
    const [shortcutsOpen, setShortcutsOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" className={cn('size-9 px-0 lg:w-auto lg:px-2.5', chromeButton)} aria-label="Help">
                        <CircleHelp className="size-[18px]!" />
                        <span className="hidden lg:inline">Help</span>
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

/**
 * Bell with an empty inbox until notifications exist. `unread` shows the green dot; leave it off until there is
 * a real unread count.
 */
export function NotificationsMenu({ unread = false }: { unread?: boolean }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className={cn('relative size-9', chromeButton)} aria-label="Notifications">
                    <Bell className="size-[18px]!" />
                    {unread && <span className="bg-sidebar-ring ring-chrome absolute top-2 right-2 size-2 rounded-full ring-2" aria-hidden />}
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

interface AccountTriggerProps extends ComponentProps<'button'> {
    name: string;
    /** Second line, e.g. the role ("Administrator"). */
    subtitle?: string | null;
}

/** Top-bar account button: avatar, name and role (from lg), chevron. Wrap in a DropdownMenuTrigger asChild. */
export function AccountTrigger({ name, subtitle, className, ...props }: AccountTriggerProps) {
    return (
        <button
            type="button"
            aria-label="Account menu"
            className={cn(
                'ml-1 flex h-11 items-center gap-2.5 rounded-lg px-1.5 text-left outline-none focus-visible:ring-[3px] lg:pr-2.5 lg:pl-1.5',
                chromeButton,
                className,
            )}
            {...props}
        >
            <InitialsAvatar name={name} size="md" className="bg-info text-chrome-foreground size-9" />
            <span className="hidden min-w-0 leading-tight lg:grid">
                <span className="text-chrome-foreground max-w-40 truncate text-sm font-semibold">{name}</span>
                {subtitle && <span className="text-chrome-muted max-w-40 truncate text-xs">{subtitle}</span>}
            </span>
            <ChevronDown className="hidden size-4 lg:block" aria-hidden />
        </button>
    );
}
