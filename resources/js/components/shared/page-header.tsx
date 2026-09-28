import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Fragment, type ReactNode } from 'react';

export interface PageCrumb {
    title: string;
    /** Omit for the current page. */
    href?: string;
}

interface PageHeaderProps {
    /** Usually plain text; a node is allowed for things like a masked licence key. */
    title: ReactNode;
    description?: ReactNode;
    /** Buttons shown on the right (below the title on phones). One primary button at most. */
    actions?: ReactNode;
    /** Trail above the title, e.g. [{ title: 'Tenants', href }, { title: 'Khan Mini Mart' }]. */
    breadcrumbs?: PageCrumb[];
    /** A single "← Plans" link above the title; use instead of breadcrumbs on simple detail pages. */
    back?: { href: string; label: string };
    /** Shown right after the title, usually a StatusBadge. */
    status?: ReactNode;
    /** Avatar or icon tile shown left of the title (detail pages). */
    media?: ReactNode;
    /** A row of small facts under the description (e.g. "Customer since 24 Sept 2026 · 2 branches"). */
    meta?: ReactNode;
    /** Underline tabs (PageTabs) rendered under the header, full width with a divider. */
    tabs?: ReactNode;
    className?: string;
}

/**
 * The top of every page: breadcrumbs or back link, title (28px bold, tight tracking), optional status, one-line
 * description, actions on the right, optional tabs. Pages never hand-roll their own title block.
 */
export function PageHeader({ title, description, actions, breadcrumbs, back, status, media, meta, tabs, className }: PageHeaderProps) {
    return (
        <header className={cn('flex flex-col gap-4', tabs && 'gap-5', className)}>
            {(breadcrumbs?.length || back) && (
                <nav aria-label="Breadcrumb" className="-mb-1 flex min-w-0 items-center text-sm">
                    {back && !breadcrumbs?.length && (
                        <Link
                            href={back.href}
                            className="text-muted-foreground hover:text-foreground -ml-1 inline-flex items-center gap-1 rounded-md px-1 py-0.5 font-medium transition-colors"
                        >
                            <ChevronLeft className="size-4" aria-hidden />
                            {back.label}
                        </Link>
                    )}
                    {breadcrumbs && breadcrumbs.length > 0 && (
                        <ol className="text-muted-foreground flex min-w-0 flex-wrap items-center gap-1">
                            {breadcrumbs.map((crumb, index) => {
                                const last = index === breadcrumbs.length - 1;

                                return (
                                    <Fragment key={`${crumb.title}-${index}`}>
                                        <li className={cn('min-w-0', last && 'truncate')}>
                                            {crumb.href && !last ? (
                                                <Link href={crumb.href} className="hover:text-foreground font-medium transition-colors">
                                                    {crumb.title}
                                                </Link>
                                            ) : (
                                                <span
                                                    aria-current={last ? 'page' : undefined}
                                                    className={cn('font-medium', last && 'text-foreground')}
                                                >
                                                    {crumb.title}
                                                </span>
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
                    )}
                </nav>
            )}

            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 items-start gap-4">
                    {media && <div className="shrink-0">{media}</div>}
                    <div className="min-w-0 space-y-1">
                        <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                            <h1 className="text-foreground truncate text-2xl leading-8 font-bold tracking-[-0.025em] sm:text-[28px] sm:leading-9">
                                {title}
                            </h1>
                            {status}
                        </div>
                        {description && <div className="text-muted-foreground max-w-3xl text-sm leading-6">{description}</div>}
                        {meta && <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 pt-1 text-[13px]">{meta}</div>}
                    </div>
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end sm:pt-0.5">{actions}</div>}
            </div>

            {tabs}
        </header>
    );
}

export default PageHeader;
