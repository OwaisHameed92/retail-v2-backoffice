import { AuthBrandPanel, type AuthVariant } from '@/components/auth/auth-brand-panel';
import BrandLogo from '@/components/brand-logo';
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
 * Sign-in shell for every signed-out page: the aurora brand panel with a product preview on the left (desktop), and a
 * borderless form on a clean white canvas on the right. The full logo sits at the top of the form, so phones (which
 * hide the panel) still see it once. Legal links are deliberately not shown here (the /legal pages stay routable).
 */
export default function AuthSplitLayout({ children, title, description, variant = 'customer' }: AuthLayoutProps) {
    const staff = variant === 'staff';
    const year = new Date().getFullYear();

    return (
        <div className="bg-card min-h-dvh lg:grid lg:grid-cols-[minmax(0,9fr)_minmax(0,11fr)]">
            <AuthBrandPanel variant={variant} />

            <main className="bg-card relative flex min-h-dvh flex-col items-center justify-center px-5 py-10 sm:px-8 lg:py-14">
                <div className={cn('motion-safe:animate-auth-rise relative w-full', variant === 'trial' ? 'max-w-[600px]' : 'max-w-[420px]')}>
                    <div className="text-card-foreground">
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

                        <div className="mt-10 mb-8 space-y-2.5">
                            <h1 className="text-foreground text-[1.75rem] leading-9 font-bold tracking-[-0.025em] text-balance">{title}</h1>
                            {description && <p className="text-muted-foreground text-[15px] leading-6 text-pretty">{description}</p>}
                        </div>

                        {children}
                    </div>

                    <footer className="text-muted-foreground mt-10 flex justify-center text-xs">
                        <p className="flex items-center gap-1.5">
                            <LockKeyhole className="text-success size-3.5" aria-hidden />
                            Secure sign-in · encrypted connection
                        </p>
                    </footer>
                    <p className="text-muted-foreground mt-3 text-center text-xs lg:hidden">© {year} Switch &amp; Save</p>
                </div>
            </main>
        </div>
    );
}
