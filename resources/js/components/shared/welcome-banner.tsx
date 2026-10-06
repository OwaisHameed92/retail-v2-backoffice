import { BrandWaves } from '@/components/shell/brand-waves';
import { dateLocale, zonedDateFormat } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Moon, Sun, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

const shopHour = (now: Date) => Number(zonedDateFormat(dateLocale(), { hour: 'numeric', hour12: false }).format(now));

/** "Good morning" / "Good afternoon" / "Good evening" for the current time in the profile's time zone. */
export function greeting(now: Date = new Date()): string {
    const hour = shopHour(now);

    return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
}

/** The sun until 6pm, then the moon (the profile's time zone). */
function greetingIcon(now: Date = new Date()): LucideIcon {
    const hour = shopHour(now);

    return hour < 18 ? Sun : Moon;
}

interface WelcomeBannerProps {
    /** First name; the title becomes "Good afternoon, Owais". */
    name?: string;
    /** Overrides the greeting title. */
    title?: ReactNode;
    subtitle?: ReactNode;
    /** Icon in the soft tile on the left; defaults to the sun (moon in the evening). */
    icon?: LucideIcon;
    /** Right side: period picker and the primary action. */
    actions?: ReactNode;
    className?: string;
}

/**
 * Dashboard welcome hero (reference-light-final.webp): the light mint wash with soft waves, a sun icon in a white
 * tile, greeting + one line, actions on the right.
 */
export function WelcomeBanner({ name, title, subtitle, icon, actions, className }: WelcomeBannerProps) {
    const Icon = icon ?? greetingIcon();

    return (
        <section
            className={cn(
                'bg-brand-wash border-primary/10 rounded-card shadow-card relative flex flex-col gap-4 overflow-hidden border px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6',
                className,
            )}
        >
            <BrandWaves className="w-[60%]" />
            <div className="relative flex min-w-0 items-center gap-4">
                <span className="bg-card/80 text-primary ring-primary/10 hidden size-14 shrink-0 items-center justify-center rounded-2xl shadow-xs ring-1 sm:flex">
                    <Icon className="size-7" strokeWidth={1.75} aria-hidden />
                </span>
                <div className="min-w-0">
                    <h1 className="text-foreground text-2xl leading-8 font-bold tracking-[-0.025em] sm:text-[28px] sm:leading-9">
                        {title ?? `${greeting()}${name ? `, ${name}` : ''}`}
                    </h1>
                    {subtitle && <p className="text-muted-foreground mt-1 text-sm sm:text-[15px]">{subtitle}</p>}
                </div>
            </div>
            {actions && <div className="relative flex flex-wrap items-center gap-2 sm:shrink-0">{actions}</div>}
        </section>
    );
}

export default WelcomeBanner;
