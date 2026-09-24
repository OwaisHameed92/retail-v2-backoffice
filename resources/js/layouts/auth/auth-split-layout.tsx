import AppLogoIcon from '@/components/app-logo-icon';
import BrandLogo from '@/components/brand-logo';
import { Link } from '@inertiajs/react';
import { BarChart3, Building2, CircleCheck, KeyRound, ScrollText, ShieldCheck, Store, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export type AuthVariant = 'customer' | 'staff';

interface AuthLayoutProps {
    children: ReactNode;
    title?: string;
    description?: ReactNode;
    /** "staff" marks the admin login as the Switch & Save staff area. */
    variant?: AuthVariant;
}

const copy: Record<AuthVariant, { eyebrow: string; heading: string; body: string; points: { icon: LucideIcon; text: string }[] }> = {
    customer: {
        eyebrow: 'Backoffice',
        heading: 'Smart Solutions for Smart Businesses',
        body: 'The cloud backoffice for your Switch & Save tills.',
        points: [
            { icon: BarChart3, text: 'Sales, stock and cash-ups from every till in one place' },
            { icon: Store, text: 'Every branch side by side, or one at a time' },
            { icon: ShieldCheck, text: 'Your tills sync securely, even after going offline' },
        ],
    },
    staff: {
        eyebrow: 'Staff area',
        heading: 'Switch & Save admin console',
        body: 'For Switch & Save staff only. Customers log in at the backoffice.',
        points: [
            { icon: Building2, text: 'Set up tenants, branches and tills' },
            { icon: KeyRound, text: 'Issue, renew and suspend licences' },
            { icon: ScrollText, text: 'Every change is recorded in the audit log' },
        ],
    },
};

/** Quiet geometric pattern for the brand panel: a fine grid and two large rings, white at low opacity. */
function BrandPattern() {
    return (
        <svg className="text-primary-foreground pointer-events-none absolute inset-0 h-full w-full" aria-hidden>
            <defs>
                <pattern id="auth-grid" width="32" height="32" patternUnits="userSpaceOnUse">
                    <path d="M32 0H0V32" fill="none" stroke="currentColor" strokeOpacity="0.07" />
                </pattern>
                <radialGradient id="auth-fade" cx="20%" cy="0%" r="90%">
                    <stop offset="0%" stopColor="currentColor" stopOpacity="0.16" />
                    <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                </radialGradient>
            </defs>
            <rect width="100%" height="100%" fill="url(#auth-grid)" />
            <rect width="100%" height="100%" fill="url(#auth-fade)" />
            <circle cx="100%" cy="100%" r="260" fill="none" stroke="currentColor" strokeOpacity="0.1" />
            <circle cx="100%" cy="100%" r="380" fill="none" stroke="currentColor" strokeOpacity="0.07" />
            <circle cx="100%" cy="100%" r="500" fill="none" stroke="currentColor" strokeOpacity="0.05" />
        </svg>
    );
}

/**
 * Auth template: flat brand-blue panel on the left (logo, tagline, three value points), the form on the right.
 * Phones show only the form with the logo above it.
 */
export default function AuthSplitLayout({ children, title, description, variant = 'customer' }: AuthLayoutProps) {
    const text = copy[variant];
    const year = new Date().getFullYear();

    return (
        <div className="bg-background grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <aside className="bg-brand-panel text-primary-foreground relative hidden overflow-hidden lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-14">
                <BrandPattern />
                <Link
                    href={route('home')}
                    className="focus-visible:ring-primary-foreground/60 relative flex items-center gap-3 self-start rounded-lg outline-none focus-visible:ring-2"
                >
                    <span className="bg-primary-foreground flex size-10 items-center justify-center rounded-full shadow-sm">
                        <AppLogoIcon className="size-9" alt="" />
                    </span>
                    <span className="text-lg font-semibold tracking-tight">Switch &amp; Save</span>
                </Link>

                <div className="relative max-w-md space-y-8">
                    <div className="space-y-3">
                        <span className="bg-primary-foreground/12 text-primary-foreground ring-primary-foreground/20 inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold tracking-wide uppercase ring-1">
                            {text.eyebrow}
                        </span>
                        <h2 className="text-3xl leading-tight font-semibold tracking-[-0.02em] text-balance">{text.heading}</h2>
                        <p className="text-primary-foreground/75 text-base">{text.body}</p>
                    </div>
                    <ul className="space-y-4">
                        {text.points.map((point) => (
                            <li key={point.text} className="text-primary-foreground/90 flex items-start gap-3 text-[15px]">
                                <span className="bg-primary-foreground/12 ring-primary-foreground/15 flex size-8 shrink-0 items-center justify-center rounded-lg ring-1">
                                    <point.icon className="size-4" aria-hidden />
                                </span>
                                <span className="pt-1.5">{point.text}</span>
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="text-primary-foreground/60 relative text-sm">© {year} Switch &amp; Save. Smart Solutions for Smart Businesses.</p>
            </aside>

            <main className="flex flex-col items-center justify-center px-5 py-10 sm:px-8">
                <div className="w-full max-w-[400px]">
                    <div className="mb-8 flex items-center justify-between gap-3 lg:hidden">
                        <Link href={route('home')} aria-label="Switch & Save home">
                            <BrandLogo className="h-9" />
                        </Link>
                        {variant === 'staff' && (
                            <span className="bg-primary-soft text-accent-foreground rounded-full px-2.5 py-1 text-xs font-semibold">Staff area</span>
                        )}
                    </div>

                    <div className="mb-7 space-y-2">
                        {variant === 'staff' && (
                            <p className="text-primary hidden items-center gap-1.5 text-xs font-semibold tracking-wide uppercase lg:flex">
                                <CircleCheck className="size-3.5" aria-hidden />
                                Staff area
                            </p>
                        )}
                        <h1 className="text-foreground text-2xl leading-8 font-semibold tracking-[-0.02em]">{title}</h1>
                        {description && <p className="text-muted-foreground text-sm leading-6">{description}</p>}
                    </div>

                    {children}
                </div>
            </main>
        </div>
    );
}
