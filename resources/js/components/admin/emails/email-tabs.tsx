import { PageTabs } from '@/components/shared/page-tabs';

/** Log / Templates switch under the Emails page header. Each tab is its own URL. */
export function EmailTabs() {
    return (
        <PageTabs
            label="Email sections"
            tabs={[
                { label: 'Log', href: route('admin.emails.index'), active: route().current('admin.emails.index') },
                { label: 'Templates', href: route('admin.emails.templates'), active: route().current('admin.emails.templates') },
            ]}
        />
    );
}

export default EmailTabs;
