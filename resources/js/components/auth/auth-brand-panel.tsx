import { CustomerPreview, StaffPreview } from '@/components/auth/auth-preview';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Fingerprint, Lock, MapPin, ScrollText, ShieldCheck, Store, UsersRound, type LucideIcon } from 'lucide-react';

export type AuthVariant = 'customer' | 'staff' | 'trial';

interface PanelCopy {
    label: string;
    /** The headline before the key phrase; `highlight` is set in the brand gradient. */
    heading: string;
    highlight: string;
    body: string;
    trust: { icon: LucideIcon; text: string }[];
}

const customerTrust = [
    { icon: MapPin, text: 'UK data hosting' },
    { icon: Lock, text: 'Bank-level encryption' },
    { icon: ShieldCheck, text: 'GDPR ready' },
    { icon: Store, text: 'UK convenience stores' },
];

const copy: Record<AuthVariant, PanelCopy> = {
    customer: {
        label: 'Business backoffice',
        heading: 'Run every shop from',
        highlight: 'one place',
        body: 'Sales, stock, cash-ups and staff from all your Switch & Save tills — live.',
        trust: customerTrust,
    },
    trial: {
        label: 'Free 7-day trial',
        heading: 'Run your shop on',
        highlight: 'Switch & Save',
        body: 'The EPOS till and cloud backoffice for UK convenience stores, newsagents and grocers. We set it up with you.',
        trust: customerTrust,
    },
    staff: {
        label: 'Switch & Save staff console',
        heading: 'Every customer, licence and till',
        highlight: 'in view',
        body: 'Tenants, licences, billing and till health for every Switch & Save business, in one console.',
        trust: [
            { icon: UsersRound, text: 'Role-based access' },
            { icon: ScrollText, text: 'Audit-logged' },
            { icon: Fingerprint, text: 'Staff accounts only' },
            { icon: MapPin, text: 'UK data hosting' },
        ],
    },
};

/** Staggered entrance (with the `motion-safe:animate-auth-rise` class, so reduced-motion users see it at once). */
const delay = (ms: number) => ({ animationDelay: `${ms}ms` });

/**
 * Left half of the sign-in screens (desktop only): a near-black navy base with blurred brand "aurora" glows and a
 * faint grain, the full white logo, a bold headline with its key phrase in the brand gradient, a dark-glass product
 * preview and a quiet trust row. The staff console uses a cooler, less green aurora.
 */
export function AuthBrandPanel({ variant }: { variant: AuthVariant }) {
    const text = copy[variant];
    const staff = variant === 'staff';
    const year = new Date().getFullYear();

    return (
        <aside
            className={cn(
                'relative isolate hidden overflow-hidden text-white lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col lg:gap-7 lg:p-10 xl:[@media(min-height:900px)]:gap-9 xl:[@media(min-height:900px)]:p-14',
                staff ? 'bg-auth-panel-staff' : 'bg-auth-panel',
            )}
        >
            <div className="pointer-events-none absolute inset-0 -z-10" aria-hidden>
                <div className="auth-aurora auth-aurora-a" />
                <div className="auth-aurora auth-aurora-c" />
                <div className="auth-aurora auth-aurora-b" />
                <div className="auth-vignette absolute inset-0" />
                <div className="auth-grain absolute inset-0" />
            </div>

            <header className="motion-safe:animate-auth-rise relative">
                <Link
                    href={route('home')}
                    aria-label="Switch & Save home"
                    className="-m-1.5 inline-flex rounded-lg p-1.5 outline-none focus-visible:ring-2 focus-visible:ring-white/60"
                >
                    <img
                        src="/images/brand/switch-save-logo-dark.png"
                        alt=""
                        width={1024}
                        height={205}
                        decoding="async"
                        className="h-10 w-auto object-contain xl:h-11"
                    />
                </Link>
            </header>

            <div className="motion-safe:animate-auth-rise relative max-w-xl shrink-0" style={delay(80)}>
                <p className="inline-flex items-center gap-2 rounded-full bg-white/[0.06] px-3 py-1 text-xs font-medium text-white/80 ring-1 ring-white/10 backdrop-blur">
                    <span className={cn('size-1.5 rounded-full', staff ? 'bg-[#5b95ff]' : 'bg-[#2ee06a]')} aria-hidden />
                    {text.label}
                </p>
                <h2 className="mt-5 text-[2.5rem] leading-[1.06] font-extrabold tracking-[-0.035em] text-balance xl:text-[3.25rem] xl:leading-[1.03] 2xl:text-[3.5rem]">
                    {text.heading} <span className="auth-gradient-text">{text.highlight}</span>
                </h2>
                <p className="mt-4 max-w-md text-[17px] leading-7 text-pretty text-white/70">{text.body}</p>
            </div>

            {/* Takes the space left over; the preview scales to fit it and hides when there is too little (auth-preview-slot). */}
            <div className="auth-preview-slot relative -my-2 flex min-h-0 flex-1 items-center">
                <div className="auth-preview">{staff ? <StaffPreview /> : <CustomerPreview />}</div>
            </div>

            <footer className="motion-safe:animate-auth-rise relative shrink-0 space-y-4" style={delay(200)}>
                <ul className="flex flex-wrap items-center gap-x-4 gap-y-2 [@media(max-height:640px)]:hidden">
                    {text.trust.map((item) => (
                        <li key={item.text} className="flex items-center gap-1.5 text-xs whitespace-nowrap text-white/70">
                            <item.icon className="size-3.5 text-white/55" aria-hidden />
                            {item.text}
                        </li>
                    ))}
                </ul>
                <p className="border-t border-white/10 pt-4 text-xs text-white/55">© {year} Switch &amp; Save. Smart Solutions for Smart Businesses.</p>
            </footer>
        </aside>
    );
}
