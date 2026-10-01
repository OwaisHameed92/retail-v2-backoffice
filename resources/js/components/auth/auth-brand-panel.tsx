import AppLogoIcon from '@/components/app-logo-icon';
import { CustomerPreview, StaffPreview } from '@/components/auth/auth-preview';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Fingerprint, Lock, MapPin, ScrollText, ShieldCheck, Store, UsersRound, type LucideIcon } from 'lucide-react';

export type AuthVariant = 'customer' | 'staff' | 'trial';

interface PanelCopy {
    label: string;
    heading: string;
    body: string;
    trust: { icon: LucideIcon; text: string }[];
}

const customerTrust = [
    { icon: MapPin, text: 'UK data hosting' },
    { icon: Lock, text: 'Bank-level encryption' },
    { icon: ShieldCheck, text: 'GDPR ready' },
    { icon: Store, text: 'Built for UK convenience stores' },
];

const copy: Record<AuthVariant, PanelCopy> = {
    customer: {
        label: 'Business backoffice',
        heading: 'Run every shop from one place',
        body: 'Sales, stock, cash-ups and staff from all your Switch & Save tills — live.',
        trust: customerTrust,
    },
    trial: {
        label: 'Free 7-day trial',
        heading: 'Run your shop on Switch & Save',
        body: 'The EPOS till and cloud backoffice for UK convenience stores, newsagents and grocers. We set it up with you.',
        trust: customerTrust,
    },
    staff: {
        label: 'Switch & Save staff console',
        heading: 'Every customer, licence and till in view',
        body: 'Tenants, licences, billing and till health for every Switch & Save business, in one console.',
        trust: [
            { icon: UsersRound, text: 'Role-based access' },
            { icon: ScrollText, text: 'Every change audit-logged' },
            { icon: Fingerprint, text: 'Staff accounts only' },
            { icon: MapPin, text: 'UK data hosting' },
        ],
    },
};

/** Staggered entrance (with the `motion-safe:animate-auth-rise` class, so reduced-motion users see it at once). */
const delay = (ms: number) => ({ animationDelay: `${ms}ms` });

/**
 * Left half of the sign-in screens (desktop only): deep brand gradient, fine grid, soft glows, the headline, a
 * floating product preview built from real UI cards, and a trust row. The staff console uses a darker palette.
 */
export function AuthBrandPanel({ variant }: { variant: AuthVariant }) {
    const text = copy[variant];
    const staff = variant === 'staff';
    const year = new Date().getFullYear();

    return (
        <aside
            className={cn(
                'text-auth-ink relative hidden overflow-hidden lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col lg:gap-6 lg:p-10 xl:[@media(min-height:900px)]:gap-8 xl:[@media(min-height:900px)]:p-14',
                staff ? 'bg-auth-panel-staff' : 'bg-auth-panel',
            )}
        >
            <div className="auth-panel-grid absolute inset-0" aria-hidden />
            <svg
                className="text-auth-ink pointer-events-none absolute inset-x-0 bottom-0 h-48 w-full opacity-[0.07]"
                viewBox="0 0 800 160"
                preserveAspectRatio="none"
                fill="none"
                aria-hidden
            >
                <path d="M0 120C140 90 260 50 400 64S640 140 800 92V160H0Z" fill="currentColor" />
                <path d="M0 140C160 112 300 86 440 98S680 150 800 120V160H0Z" fill="currentColor" />
                <path d="M0 100C150 70 270 30 410 44S650 120 800 70" stroke="currentColor" strokeWidth="1.5" />
            </svg>

            <header className="motion-safe:animate-auth-rise relative flex items-center gap-3">
                <Link
                    href={route('home')}
                    aria-label="Switch & Save home"
                    className="focus-visible:ring-auth-ink/60 flex items-center gap-2.5 rounded-lg outline-none focus-visible:ring-2"
                >
                    <AppLogoIcon className="ring-auth-ink/20 size-9 rounded-full ring-1" alt="" />
                    <span className="text-[15px] font-semibold tracking-[-0.01em]">Switch &amp; Save</span>
                </Link>
                <span className="bg-auth-ink/10 ring-auth-ink/15 text-auth-ink/90 rounded-full px-2.5 py-1 text-xs font-medium ring-1 backdrop-blur">
                    {text.label}
                </span>
            </header>

            <div className="motion-safe:animate-auth-rise relative max-w-xl shrink-0 space-y-4" style={delay(80)}>
                <h2 className="text-[2.25rem] leading-[1.1] font-semibold tracking-[-0.03em] text-balance xl:text-[2.75rem] xl:leading-[1.08]">
                    {text.heading}
                </h2>
                <p className="text-auth-ink/80 max-w-md text-lg leading-7 text-pretty">{text.body}</p>
            </div>

            {/* Takes the space left over; the preview scales to fit it and hides when there is too little (auth-preview-slot). */}
            <div className="auth-preview-slot relative -my-2 flex min-h-0 flex-1 items-center">
                <div className="auth-preview">{staff ? <StaffPreview /> : <CustomerPreview />}</div>
            </div>

            <footer className="motion-safe:animate-auth-rise relative shrink-0 space-y-6" style={delay(200)}>
                <ul className="grid grid-cols-2 gap-x-6 gap-y-3 [@media(max-height:640px)]:hidden">
                    {text.trust.map((item) => (
                        <li key={item.text} className="text-auth-ink/85 flex items-center gap-2.5 text-sm">
                            <span className="bg-auth-ink/10 ring-auth-ink/15 flex size-7 shrink-0 items-center justify-center rounded-lg ring-1">
                                <item.icon className="size-3.5" aria-hidden />
                            </span>
                            {item.text}
                        </li>
                    ))}
                </ul>
                <p className="border-auth-ink/15 text-auth-ink/75 border-t pt-5 text-xs">
                    © {year} Switch &amp; Save. Smart Solutions for Smart Businesses.
                </p>
            </footer>
        </aside>
    );
}
