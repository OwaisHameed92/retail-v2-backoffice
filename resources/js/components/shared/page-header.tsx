import { BrandWaves } from '@/components/shell/brand-waves';
import { usePageIcon } from '@/components/shell/page-icon';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, type LucideIcon } from 'lucide-react';
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
    /** Icon in the white tile; defaults to the page's sidebar nav icon (`usePageIcon`). */
    icon?: LucideIcon;
    /** Avatar shown instead of the icon tile (detail pages of a business, person or plan). */
    media?: ReactNode;
    /** A row of small facts under the description (e.g. "Customer since 24 Sept 2026 · 2 branches"). */
    meta?: ReactNode;
    /** Underline tabs (PageTabs) rendered under the header band, full width with a divider. */
    tabs?: ReactNode;
    className?: string;
}

function PageBreadcrumbs({ breadcrumbs, back }: Pick<PageHeaderProps, 'breadcrumbs' | 'back'>) {
    if (breadcrumbs?.length) {
        return (
            <nav aria-label="Breadcrumb" className="flex min-w-0 items-center text-sm">
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
                                        <span aria-current={last ? 'page' : undefined} className={cn('font-medium', last && 'text-foreground')}>
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
            </nav>
        );
    }

    if (!back) {
        return null;
    }

    return (
        <nav aria-label="Breadcrumb" className="flex min-w-0 items-center text-sm">
            <Link
                href={back.href}
                className="text-muted-foreground hover:text-foreground -ml-1 inline-flex items-center gap-1 rounded-md px-1 py-0.5 font-medium transition-colors"
            >
                <ChevronLeft className="size-4" aria-hidden />
                {back.label}
            </Link>
        </nav>
    );
}

/**
 * The top of every page: a slim version of the dashboard hero (mint wash with soft waves) holding the breadcrumbs or
 * back link, a white tile with the page's nav icon in green, the title with an optional status, a one-line
 * description and the actions on the right (under the title on phones). Tabs sit under the band. Pages never
 * hand-roll their own title block; dashboards use the larger `WelcomeBanner` instead.
 */
export function PageHeader({ title, description, actions, breadcrumbs, back, status, icon, media, meta, tabs, className }: PageHeaderProps) {
    const navIcon = usePageIcon();
    const Icon = icon ?? navIcon;

    return (
        <header className={cn('flex flex-col gap-4', tabs && 'gap-5', className)}>
            <div className="bg-brand-wash border-primary/10 rounded-card shadow-card relative overflow-hidden border px-4 py-4 sm:px-6 sm:py-5">
                <BrandWaves className="w-[55%]" />
                <div className="relative flex flex-col gap-3">
                    <PageBreadcrumbs breadcrumbs={breadcrumbs} back={back} />

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex min-w-0 items-center gap-3 sm:gap-4">
                            {media ? (
                                <div className="shrink-0">{media}</div>
                            ) : (
                                <span className="bg-card/80 text-primary ring-primary/10 flex size-11 shrink-0 items-center justify-center rounded-xl shadow-xs ring-1 sm:size-12 sm:rounded-2xl">
                                    <Icon className="size-[22px] sm:size-6" strokeWidth={1.75} aria-hidden />
                                </span>
                            )}
                            <div className="min-w-0">
                                <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                                    <h1 className="text-foreground truncate text-[22px] leading-7 font-bold tracking-[-0.025em] sm:text-2xl sm:leading-8">
                                        {title}
                                    </h1>
                                    {status}
                                </div>
                                {description && <div className="text-muted-foreground mt-0.5 max-w-3xl text-sm leading-6">{description}</div>}
                                {meta && (
                                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 pt-1 text-[13px]">{meta}</div>
                                )}
                            </div>
                        </div>
                        {actions && <div className="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">{actions}</div>}
                    </div>
                </div>
            </div>

            {tabs}
        </header>
    );
}

export default PageHeader;
