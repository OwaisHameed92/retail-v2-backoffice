import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

const tabs = [
    { label: 'Log', route: 'admin.emails.index' },
    { label: 'Templates', route: 'admin.emails.templates' },
] as const;

/** Log / Templates switch under the Emails page header. Each tab is its own URL. */
export function EmailTabs() {
    return (
        <nav aria-label="Email sections" className="-mt-2 border-b">
            <ul className="flex gap-6">
                {tabs.map((tab) => {
                    const active = route().current(tab.route);

                    return (
                        <li key={tab.route}>
                            <Link
                                href={route(tab.route)}
                                aria-current={active ? 'page' : undefined}
                                prefetch
                                className={cn(
                                    'focus-visible:ring-ring -mb-px inline-flex h-10 items-center border-b-2 px-0.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                    active ? 'border-primary text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                                )}
                            >
                                {tab.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

export default EmailTabs;
