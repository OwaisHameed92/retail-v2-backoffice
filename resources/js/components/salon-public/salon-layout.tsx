import { cn } from '@/lib/utils';
import { Head } from '@inertiajs/react';
import { Phone } from 'lucide-react';
import { type ReactNode } from 'react';
import { telHref } from './api';
import { type SalonShop } from './types';

/**
 * The public salon pages' frame (W3a): mobile-first, in the shop's name (never the portal's). With `embed` (the shop's
 * website widget, `?embed=1`) there is no header or footer, only the page.
 */
export function SalonLayout({ shop, title, embed = false, children }: { shop: SalonShop; title: string; embed?: boolean; children: ReactNode }) {
    const initial = shop.name.trim().charAt(0).toUpperCase() || 'S';

    return (
        <div className={cn('bg-background text-foreground flex min-h-screen flex-col text-sm', embed && 'min-h-0 bg-transparent')}>
            <Head title={title === shop.name ? shop.name : `${title} · ${shop.name}`} />

            {!embed && (
                <header className="border-border bg-card border-b">
                    <div className="mx-auto flex w-full max-w-2xl items-center gap-3 px-4 py-3">
                        <span
                            aria-hidden="true"
                            className="bg-primary text-primary-foreground grid size-10 shrink-0 place-items-center rounded-full text-base font-semibold"
                        >
                            {initial}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-base font-semibold tracking-tight">{shop.name}</p>
                            <p className="text-muted-foreground text-xs">Book online</p>
                        </div>
                        {shop.phone !== '' && (
                            <a
                                href={telHref(shop.phone)}
                                className="border-border hover:bg-muted focus-visible:ring-ring/30 inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium outline-none focus-visible:ring-[3px]"
                            >
                                <Phone className="size-3.5" aria-hidden="true" />
                                <span className="hidden sm:inline">{shop.phone}</span>
                                <span className="sm:hidden">Call</span>
                            </a>
                        )}
                    </div>
                </header>
            )}

            <main className={cn('mx-auto w-full max-w-2xl flex-1 px-4', embed ? 'py-3' : 'py-6')}>{children}</main>

            {!embed && (
                <footer className="text-muted-foreground mx-auto w-full max-w-2xl px-4 pt-2 pb-8 text-center text-xs">
                    {shop.name} · online booking and queue. The shop confirms every booking itself.
                </footer>
            )}
        </div>
    );
}

/** A white card section with an optional numbered step heading. */
export function SalonSection({
    step,
    title,
    description,
    children,
    className,
}: {
    step?: number;
    title: string;
    description?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('border-border bg-card rounded-xl border p-4 shadow-xs sm:p-5', className)}>
            <div className="mb-3 flex items-start gap-3">
                {step !== undefined && (
                    <span
                        aria-hidden="true"
                        className="bg-primary/10 text-primary grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold tabular-nums"
                    >
                        {step}
                    </span>
                )}
                <div className="min-w-0">
                    <h2 className="text-base font-semibold">{title}</h2>
                    {description && <p className="text-muted-foreground mt-0.5">{description}</p>}
                </div>
            </div>
            {children}
        </section>
    );
}

/** A coloured notice: info, success, warning or danger (brand soft tints). */
export function SalonNotice({
    tone = 'info',
    title,
    children,
}: {
    tone?: 'info' | 'success' | 'warning' | 'danger';
    title: string;
    children?: ReactNode;
}) {
    const tones = {
        info: 'bg-info-soft border-primary/20',
        success: 'bg-success-soft border-success/30',
        warning: 'bg-warning-soft border-warning/30',
        danger: 'bg-danger-soft border-destructive/30',
    };

    return (
        <div role={tone === 'danger' || tone === 'warning' ? 'alert' : 'status'} className={cn('rounded-xl border p-4', tones[tone])}>
            <p className="font-semibold">{title}</p>
            {children && <div className="text-foreground/80 mt-1 space-y-1">{children}</div>}
        </div>
    );
}
