import AppLogoIcon from '@/components/app-logo-icon';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronRight, Search } from 'lucide-react';
import { Fragment, type ComponentProps, type ReactNode } from 'react';

import { modKey } from '@/components/shell/topbar-menus';

export { AccountTrigger, chromeButton, HelpMenu, modKey, NotificationsMenu } from '@/components/shell/topbar-menus';

export interface TopbarCrumb {
    title: string;
    href?: string;
}

/**
 * Left end of the top bar: the logo, as wide as the sidebar below it so the two read as one dark frame.
 * Shrinks to the mark when the sidebar is collapsed; on phones a menu button opens the sidebar sheet.
 */
export function TopbarBrand({ href }: { href: string }) {
    const { state } = useSidebar();
    const collapsed = state === 'collapsed';

    return (
        <div
            className={cn(
                'flex h-full shrink-0 items-center gap-1 pl-2 transition-[width] duration-200 ease-linear md:pl-4',
                'md:w-(--sidebar-width)',
                collapsed && 'md:w-(--sidebar-width-icon) md:justify-center md:pl-0',
            )}
        >
            <SidebarTrigger className="text-chrome-muted hover:bg-chrome-hover hover:text-chrome-foreground size-9 md:hidden" />
            <Link
                href={href}
                prefetch
                className="focus-visible:ring-sidebar-ring flex min-w-0 items-center gap-2.5 rounded-lg px-1 py-1 outline-none focus-visible:ring-2"
            >
                <AppLogoIcon className="size-8 shrink-0" alt="" />
                <span className={cn('hidden truncate text-[17px] font-semibold tracking-[-0.015em] sm:inline', collapsed && 'md:hidden')}>
                    <span className="text-chrome-foreground">Switch</span> <span className="text-brand-green">&amp; Save</span>
                </span>
                <span className="sr-only">Switch &amp; Save home</span>
            </Link>
        </div>
    );
}

/**
 * The 64px dark top bar (chrome), fixed above the sidebar: logo left, search centred, actions right
 * (help, notifications, account). Layouts fill the slots; ShellFrame positions it.
 */
export function Topbar({ brand, search, actions, className }: { brand: ReactNode; search?: ReactNode; actions?: ReactNode; className?: string }) {
    return (
        <header className={cn('bg-chrome-frame text-chrome-foreground border-chrome-border flex h-16 shrink-0 items-center border-b', className)}>
            {brand}
            <div className="flex h-full min-w-0 flex-1 items-center gap-2 pr-2 pl-1 sm:gap-3 sm:pr-4 md:pl-3 lg:pr-6">
                <SidebarTrigger className="text-chrome-muted hover:bg-chrome-hover hover:text-chrome-foreground hidden size-9 md:inline-flex" />
                <div className="flex min-w-0 flex-1 justify-end sm:justify-center">{search}</div>
                <div className="flex shrink-0 items-center gap-0.5 sm:gap-1">{actions}</div>
            </div>
        </header>
    );
}

/** Breadcrumb trail shown above the page content (detail pages). The last crumb is the current page. */
export function TopbarBreadcrumbs({ items, className }: { items: TopbarCrumb[]; className?: string }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <nav aria-label="Breadcrumb" className={className}>
            <ol className="text-muted-foreground flex min-w-0 items-center gap-1.5 text-[13px]">
                {items.map((item, index) => {
                    const last = index === items.length - 1;

                    return (
                        <Fragment key={`${item.title}-${index}`}>
                            <li className={cn('min-w-0 truncate', last && 'text-foreground font-medium')}>
                                {item.href && !last ? (
                                    <Link href={item.href} className="hover:text-foreground transition-colors">
                                        {item.title}
                                    </Link>
                                ) : (
                                    <span aria-current={last ? 'page' : undefined}>{item.title}</span>
                                )}
                            </li>
                            {!last && (
                                <li aria-hidden className="text-muted-foreground/50">
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

interface SearchTriggerProps extends Omit<ComponentProps<'button'>, 'children'> {
    placeholder: string;
    /** Shows it as "coming soon" (e.g. the tenant AI search). */
    disabled?: boolean;
    icon?: typeof Search;
    shortcut?: boolean;
}

/**
 * Button that looks like a search field on the dark top bar and opens a command palette. On phones it
 * collapses to an icon button.
 */
export function SearchTrigger({ placeholder, disabled = false, icon: Icon = Search, shortcut = true, className, ...props }: SearchTriggerProps) {
    return (
        <button
            type="button"
            disabled={disabled}
            title={disabled ? 'Coming soon' : undefined}
            aria-label={placeholder}
            className={cn(
                'text-chrome-muted border-chrome-border bg-chrome-input flex size-10 items-center justify-center gap-2.5 rounded-lg border text-sm outline-none',
                'sm:h-10 sm:w-full sm:max-w-xl sm:justify-start sm:px-3.5',
                'hover:bg-chrome-hover hover:border-chrome-muted/30 hover:text-chrome-foreground transition-[color,border-color,background-color] duration-150',
                'focus-visible:border-sidebar-ring focus-visible:ring-sidebar-ring/25 focus-visible:ring-[3px]',
                'disabled:hover:text-chrome-muted disabled:hover:border-chrome-border disabled:cursor-default disabled:opacity-70',
                className,
            )}
            {...props}
        >
            <Icon className="size-[18px] shrink-0" aria-hidden />
            <span className="hidden truncate sm:inline">{placeholder}</span>
            {disabled ? (
                <span className="bg-chrome-hover ml-auto hidden rounded px-1.5 text-[11px] font-medium sm:inline">Soon</span>
            ) : (
                shortcut && (
                    <kbd className="border-chrome-border bg-chrome-hover ml-auto hidden rounded-md border px-1.5 py-0.5 font-sans text-[11px] font-medium sm:inline">
                        {modKey} K
                    </kbd>
                )
            )}
        </button>
    );
}
