import { PageTabs } from '@/components/shared/page-tabs';

/** Payment types | Reasons: the two short lists every till shares (module 4.5). */
export function TillListTabs({ active }: { active: 'payment-types' | 'reasons' }) {
    return (
        <PageTabs
            label="Till lists"
            tabs={[
                { label: 'Payment types', href: route('app.payment-types.index'), active: active === 'payment-types' },
                { label: 'Reasons', href: route('app.reasons.index'), active: active === 'reasons' },
            ]}
        />
    );
}

/** Staff | Till roles (module 4.5). */
export function StaffTabs({ active }: { active: 'staff' | 'roles' }) {
    return (
        <PageTabs
            label="Staff sections"
            tabs={[
                { label: 'Staff', href: route('app.staff.index'), active: active === 'staff' },
                { label: 'Till roles', href: route('app.staff.roles'), active: active === 'roles' },
            ]}
        />
    );
}
