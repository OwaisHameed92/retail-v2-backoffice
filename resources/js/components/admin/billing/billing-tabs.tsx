import { PageTabs } from '@/components/shared/page-tabs';

/** Overview / Invoices / Payments under the Billing page header. Each tab is its own URL. */
export function BillingTabs() {
    return (
        <PageTabs
            label="Billing sections"
            tabs={[
                { label: 'Cash due', href: route('admin.billing.index'), active: route().current('admin.billing.index') },
                { label: 'Invoices', href: route('admin.billing.invoices.index'), active: route().current('admin.billing.invoices.*') },
                { label: 'Payments', href: route('admin.billing.payments.index'), active: route().current('admin.billing.payments.*') },
            ]}
        />
    );
}

export default BillingTabs;
