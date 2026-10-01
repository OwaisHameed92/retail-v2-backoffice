import { PageHeader } from '@/components/shared/page-header';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';
import { KeyRound, Palette, ShieldCheck, UserRound, type LucideIcon } from 'lucide-react';

const sidebarNavItems: { title: string; url: string; icon: LucideIcon }[] = [
    { title: 'Profile', url: '/settings/profile', icon: UserRound },
    { title: 'Password', url: '/settings/password', icon: KeyRound },
    { title: 'Security', url: '/settings/security', icon: ShieldCheck },
    { title: 'Appearance', url: '/settings/appearance', icon: Palette },
];

/**
 * Settings template: page header, a vertical section nav on the left (a horizontal strip on phones) and the
 * section's cards on the right.
 */
export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = usePage().url.split('?')[0];

    return (
        <>
            <PageHeader title="Your account" description="Your profile, password, sign-in security and how the backoffice looks for you." />

            <div className="flex flex-col gap-6 lg:flex-row lg:gap-10">
                <aside className="lg:w-52 lg:shrink-0">
                    <nav aria-label="Settings sections" className="scrollbar-none -mx-4 flex gap-1 overflow-x-auto px-4 lg:mx-0 lg:flex-col lg:px-0">
                        {sidebarNavItems.map((item) => {
                            const active = currentPath === item.url;

                            return (
                                <Link
                                    key={item.url}
                                    href={item.url}
                                    prefetch
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'flex h-9 shrink-0 items-center gap-2.5 rounded-lg px-3 text-sm font-medium transition-colors duration-150',
                                        active
                                            ? 'bg-card text-foreground shadow-card ring-border ring-1'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                >
                                    <item.icon className={cn('size-4', active ? 'text-primary' : 'text-muted-foreground')} aria-hidden />
                                    {item.title}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <div className="min-w-0 flex-1 lg:max-w-3xl">
                    <section className="bg-card shadow-card space-y-10 rounded-xl border p-5 sm:p-6">{children}</section>
                </div>
            </div>
        </>
    );
}
