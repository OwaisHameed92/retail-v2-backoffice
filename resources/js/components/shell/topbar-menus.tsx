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
import { Bell, BookOpen, ChevronDown, Keyboard, LifeBuoy, MessageSquareText } from 'lucide-react';
import { useState, type ComponentProps } from 'react';

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
export const modKey = isMac ? '⌘' : 'Ctrl';

/** Ghost button classes for icons and menus on the light top bar. */
export const chromeButton =
    'text-chrome-muted hover:bg-chrome-hover hover:text-chrome-foreground data-[state=open]:bg-chrome-hover data-[state=open]:text-chrome-foreground focus-visible:ring-sidebar-ring/35';

/** Thin vertical rule between the top-bar icons and the account button. */
export function TopbarDivider() {
    return <span className="bg-chrome-border mx-2 hidden h-7 w-px sm:block" aria-hidden />;
}

const shortcuts: { keys: string[]; label: string }[] = [
    { keys: [modKey, 'K'], label: 'Open search' },
    { keys: ['/'], label: 'Open search (when not typing)' },
    { keys: [modKey, 'B'], label: 'Show or hide the sidebar' },
    { keys: ['↑', '↓'], label: 'Move through search results' },
    { keys: ['Enter'], label: 'Open the highlighted result or row' },
    { keys: ['Esc'], label: 'Close a dialog or menu' },
];

/** "Help and support" menu (the chat bubble): keyboard shortcuts, help centre and support (soon). */
export function HelpMenu() {
    const [shortcutsOpen, setShortcutsOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className={cn('size-10', chromeButton)} aria-label="Help and support" title="Help and support">
                        <MessageSquareText className="size-5!" strokeWidth={1.75} />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-60">
                    <DropdownMenuLabel>Help and support</DropdownMenuLabel>
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
                <Button variant="ghost" size="icon" className={cn('relative size-10', chromeButton)} aria-label="Notifications">
                    <Bell className="size-5!" strokeWidth={1.75} />
                    {unread && <span className="bg-primary ring-chrome absolute top-2 right-2.5 size-2 rounded-full ring-2" aria-hidden />}
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
            <InitialsAvatar name={name} size="md" className="bg-primary text-primary-foreground size-10 text-sm" />
            <span className="hidden min-w-0 leading-tight lg:grid">
                <span className="text-chrome-foreground max-w-40 truncate text-sm font-semibold">{name}</span>
                {subtitle && <span className="text-chrome-muted max-w-40 truncate text-xs">{subtitle}</span>}
            </span>
            <ChevronDown className="text-chrome-muted ml-1 hidden size-4 lg:block" aria-hidden />
        </button>
    );
}
