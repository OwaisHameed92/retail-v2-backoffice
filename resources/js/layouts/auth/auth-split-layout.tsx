import BrandLogo from '@/components/brand-logo';
import { LegalLinks } from '@/components/legal-links';
import { BrandWaves } from '@/components/shell/brand-waves';
import { Link } from '@inertiajs/react';
import { BarChart3, Building2, CircleCheck, Headset, KeyRound, ScrollText, ShieldCheck, Store, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export type AuthVariant = 'customer' | 'staff' | 'trial';

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
    trial: {
        eyebrow: 'Free 7-day trial',
        heading: 'Run your shop on Switch & Save',
        body: 'The EPOS till and cloud backoffice for UK convenience stores, newsagents and grocers.',
        points: [
            { icon: Store, text: 'Every till and branch, set up for you' },
            { icon: BarChart3, text: 'Sales, stock and cash-ups in one place' },
            { icon: Headset, text: 'A real person calls you to get started' },
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

/**
 * Auth template: the light mint brand panel on the left (the full logo, tagline, three value points, soft waves),
 * the form on the right. Phones show only the form with the full logo above it.
 */
export default function AuthSplitLayout({ children, title, description, variant = 'customer' }: AuthLayoutProps) {
    const text = copy[variant];
    const year = new Date().getFullYear();

    return (
        <div className="bg-background grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <aside className="bg-brand-wash border-border relative hidden overflow-hidden border-r lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-14">
                <BrandWaves className="inset-y-auto bottom-0 h-40 w-full" />
                <Link
                    href={route('home')}
                    className="focus-visible:ring-ring relative self-start rounded-lg outline-none focus-visible:ring-2"
                    aria-label="Switch & Save home"
                >
                    <BrandLogo className="h-12 xl:h-14" alt="" />
                </Link>

                <div className="relative max-w-md space-y-8">
                    <div className="space-y-3">
                        <span className="bg-card text-accent-foreground ring-primary/15 inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold tracking-wide uppercase shadow-xs ring-1">
                            {text.eyebrow}
                        </span>
                        <h2 className="text-foreground text-3xl leading-tight font-semibold tracking-[-0.02em] text-balance">{text.heading}</h2>
                        <p className="text-muted-foreground text-base">{text.body}</p>
                    </div>
                    <ul className="space-y-4">
                        {text.points.map((point) => (
                            <li key={point.text} className="text-foreground flex items-start gap-3 text-[15px]">
                                <span className="bg-card text-primary ring-primary/15 flex size-8 shrink-0 items-center justify-center rounded-full shadow-xs ring-1">
                                    <point.icon className="size-4" aria-hidden />
                                </span>
                                <span className="pt-1.5">{point.text}</span>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="relative grid gap-2">
                    <p className="text-muted-foreground text-sm">© {year} Switch &amp; Save. Smart Solutions for Smart Businesses.</p>
                    <LegalLinks />
                </div>
            </aside>

            <main className="flex flex-col items-center justify-center px-5 py-10 sm:px-8">
                <div className={variant === 'trial' ? 'w-full max-w-[560px]' : 'w-full max-w-[400px]'}>
                    <div className="mb-8 flex items-center justify-between gap-3 lg:hidden">
                        <Link href={route('home')} aria-label="Switch & Save home">
                            <BrandLogo className="h-10" />
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

                    <LegalLinks className="mt-10 justify-center lg:hidden" />
                </div>
            </main>
        </div>
    );
}
