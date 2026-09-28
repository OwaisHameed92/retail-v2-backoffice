import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/** "Good morning" / "Good afternoon" / "Good evening" for the current time in Europe/London. */
export function greeting(now: Date = new Date()): string {
    const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hour12: false, timeZone: 'Europe/London' }).format(now));

    return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
}

interface WelcomeBannerProps {
    /** First name; the title becomes "Good afternoon, Owais". */
    name?: string;
    /** Overrides the greeting title. */
    title?: ReactNode;
    subtitle?: ReactNode;
    /** Right side: period picker and the primary action. */
    actions?: ReactNode;
    className?: string;
}

/** Dashboard welcome card with a very soft primary tint: greeting + one line, actions on the right. */
export function WelcomeBanner({ name, title, subtitle, actions, className }: WelcomeBannerProps) {
    return (
        <section
            className={cn(
                'border-primary/10 from-primary-soft via-primary-soft/50 to-card rounded-card flex flex-col gap-4 border bg-gradient-to-r px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6',
                className,
            )}
        >
            <div className="min-w-0">
                <h1 className="text-foreground text-2xl leading-8 font-bold tracking-[-0.025em] sm:text-[28px] sm:leading-9">
                    {title ?? `${greeting()}${name ? `, ${name}` : ''}`}
                </h1>
                {subtitle && <p className="text-muted-foreground mt-1 text-sm sm:text-[15px]">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2 sm:shrink-0">{actions}</div>}
        </section>
    );
}

export default WelcomeBanner;
