import { PageTabs } from '@/components/shared/page-tabs';
import { type AdminSharedData } from '@/components/admin/types';
import { usePage } from '@inertiajs/react';

/**
 * Overview | Trading tabs of the admin dashboard (modules 1.9 and 3.2). Trading needs `trading.view`; without it
 * no tabs are shown at all.
 */
export function DashboardTabs({ active }: { active: 'overview' | 'trading' }) {
    const { admin } = usePage<AdminSharedData>().props;

    if (!admin.abilities.includes('trading.view')) {
        return null;
    }

    return (
        <PageTabs
            label="Dashboard sections"
            tabs={[
                { label: 'Overview', href: route('admin.dashboard'), active: active === 'overview' },
                { label: 'Trading', href: route('admin.trading'), active: active === 'trading' },
            ]}
        />
    );
}
