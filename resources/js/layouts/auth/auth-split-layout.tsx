import { AuthBrandPanel, type AuthVariant } from '@/components/auth/auth-brand-panel';
import BrandLogo from '@/components/brand-logo';
import { LegalLinks } from '@/components/legal-links';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { LockKeyhole, ShieldCheck } from 'lucide-react';
import { type ReactNode } from 'react';

export type { AuthVariant };

interface AuthLayoutProps {
    children: ReactNode;
    title?: string;
    description?: ReactNode;
    /** "staff" marks the admin console sign-in; "trial" widens the card for the trial form. */
    variant?: AuthVariant;
}

/**
 * Sign-in shell for every signed-out page: the deep brand panel with a product preview on the left (desktop), and an
 * elevated white card on a faint dotted canvas on the right. The full logo sits at the top of the card, so phones
 * (which hide the panel) still see it once.
 */
export default function AuthSplitLayout({ children, title, description, variant = 'customer' }: AuthLayoutProps) {
    const staff = variant === 'staff';
    const year = new Date().getFullYear();

    return (
        <div className="bg-background min-h-dvh lg:grid lg:grid-cols-[minmax(0,9fr)_minmax(0,11fr)]">
            <AuthBrandPanel variant={variant} />

            <main className="relative flex min-h-dvh flex-col items-center justify-center overflow-hidden px-4 py-10 sm:px-8 lg:py-14">
                <div className="auth-canvas-dots absolute inset-0" aria-hidden />
                <div
                    className="bg-primary/10 pointer-events-none absolute -top-40 left-1/2 h-80 w-[36rem] -translate-x-1/2 rounded-full blur-3xl"
                    aria-hidden
                />

                <div className={cn('motion-safe:animate-auth-rise relative w-full', variant === 'trial' ? 'max-w-[600px]' : 'max-w-[440px]')}>
                    <div className="bg-card text-card-foreground shadow-auth rounded-[20px] border p-6 sm:p-10">
                        <div className="flex items-center justify-between gap-3">
                            <Link
                                href={route('home')}
                                aria-label="Switch & Save home"
                                className="focus-visible:ring-ring/40 -m-1 rounded-lg p-1 outline-none focus-visible:ring-[3px]"
                            >
                                <BrandLogo className="h-9 sm:h-10" alt="" />
                            </Link>
                            {staff && (
                                <span className="bg-primary-soft text-accent-foreground inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold">
                                    <ShieldCheck className="size-3.5" aria-hidden />
                                    Staff area
                                </span>
                            )}
                        </div>

                        <div className="mt-8 mb-7 space-y-2">
                            <h1 className="text-foreground text-2xl leading-8 font-semibold tracking-[-0.02em] text-balance">{title}</h1>
                            {description && <p className="text-muted-foreground text-sm leading-6 text-pretty">{description}</p>}
                        </div>

                        {children}
                    </div>

                    <footer className="text-muted-foreground mt-6 flex flex-col items-center gap-3 text-xs sm:flex-row sm:justify-between">
                        <p className="flex items-center gap-1.5">
                            <LockKeyhole className="text-success size-3.5" aria-hidden />
                            Secure sign-in · encrypted connection
                        </p>
                        <LegalLinks className="justify-center sm:justify-end" />
                    </footer>
                    <p className="text-muted-foreground mt-4 text-center text-xs lg:hidden">© {year} Switch &amp; Save</p>
                </div>
            </main>
        </div>
    );
}
