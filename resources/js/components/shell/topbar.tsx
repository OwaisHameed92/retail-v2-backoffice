import AppLogoIcon from '@/components/app-logo-icon';
import { BrandWaves } from '@/components/shell/brand-waves';
import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronRight, Menu, Search } from 'lucide-react';
import { Fragment, type ComponentProps, type ReactNode } from 'react';

import { chromeButton, modKey } from '@/components/shell/topbar-menus';

export { AccountTrigger, chromeButton, HelpMenu, modKey, NotificationsMenu, TopbarDivider } from '@/components/shell/topbar-menus';

export interface TopbarCrumb {
    title: string;
    href?: string;
}

/**
 * Left end of the top bar: the hamburger that shows or hides the sidebar (a sheet on phones), plus the round mark
 * linking home on phones, where the sidebar (and its logo) is hidden.
 */
export function TopbarStart({ href }: { href: string }) {
    const { toggleSidebar } = useSidebar();

    return (
        <div className="flex shrink-0 items-center gap-1.5">
            <Button variant="ghost" size="icon" className={cn('size-10', chromeButton)} onClick={toggleSidebar} aria-label="Show or hide the menu">
                <Menu className="size-[22px]!" strokeWidth={1.75} />
            </Button>
            <Link href={href} prefetch className="focus-visible:ring-sidebar-ring rounded-full outline-none focus-visible:ring-2 md:hidden">
                <AppLogoIcon className="size-8" alt="" />
                <span className="sr-only">Switch &amp; Save home</span>
            </Link>
        </div>
    );
}

/**
 * The 64px light top bar to the right of the sidebar: white fading into the soft mint wave wash
 * (reference-light-final.webp); menu button left, search, then the actions (notifications, help, account).
 * Layouts fill the slots; ShellFrame makes it sticky.
 */
export function Topbar({ home, search, actions, className }: { home: string; search?: ReactNode; actions?: ReactNode; className?: string }) {
    return (
        <header className={cn('bg-chrome-frame text-chrome-foreground border-chrome-border relative flex h-16 shrink-0 items-center overflow-hidden border-b', className)}>
            <BrandWaves className="w-[55%]" />
            <div className="relative flex h-full min-w-0 flex-1 items-center gap-2 px-2 sm:gap-4 sm:px-4 lg:px-6">
                <TopbarStart href={home} />
                <div className="flex min-w-0 flex-1 justify-end sm:justify-start">{search}</div>
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
    /** Shorter placeholder for narrow screens, so the text is never cut off mid-word. */
    shortPlaceholder?: string;
    /** Shows it as "coming soon" (e.g. the tenant AI search). */
    disabled?: boolean;
    icon?: typeof Search;
    shortcut?: boolean;
}

/**
 * Button that looks like a rounded white search field on the top bar and opens a command palette. The ⌘K hint
 * always stays on one line; on phones it collapses to an icon button.
 */
export function SearchTrigger({
    placeholder,
    shortPlaceholder,
    disabled = false,
    icon: Icon = Search,
    shortcut = true,
    className,
    ...props
}: SearchTriggerProps) {
    return (
        <button
            type="button"
            disabled={disabled}
            title={disabled ? 'Coming soon' : undefined}
            aria-label={placeholder}
            className={cn(
                'text-chrome-muted border-chrome-border bg-chrome-input flex size-10 items-center justify-center gap-2.5 rounded-xl border text-sm shadow-xs outline-none',
                'sm:h-11 sm:w-full sm:max-w-2xl sm:justify-start sm:px-4',
                'hover:border-border-strong hover:text-chrome-foreground transition-[color,border-color,box-shadow] duration-150',
                'focus-visible:border-sidebar-ring focus-visible:ring-sidebar-ring/25 focus-visible:ring-[3px]',
                'disabled:hover:text-chrome-muted disabled:hover:border-chrome-border disabled:cursor-default',
                className,
            )}
            {...props}
        >
            <Icon className="size-[18px] shrink-0" aria-hidden />
            {shortPlaceholder ? (
                <>
                    <span className="hidden min-w-0 flex-1 truncate text-left sm:inline xl:hidden">{shortPlaceholder}</span>
                    <span className="hidden min-w-0 flex-1 truncate text-left xl:inline">{placeholder}</span>
                </>
            ) : (
                <span className="hidden min-w-0 flex-1 truncate text-left sm:inline">{placeholder}</span>
            )}
            {disabled ? (
                <span className="bg-muted text-muted-foreground hidden shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap sm:inline">
                    Soon
                </span>
            ) : (
                shortcut && (
                    <kbd className="border-chrome-border bg-muted text-muted-foreground hidden shrink-0 items-center gap-0.5 rounded-md border px-1.5 py-0.5 font-sans text-[11px] leading-4 font-medium whitespace-nowrap sm:inline-flex">
                        <span>{modKey}</span>
                        <span>K</span>
                    </kbd>
                )
            )}
        </button>
    );
}
