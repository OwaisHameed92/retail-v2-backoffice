import { TenantBillingPanel } from '@/components/admin/billing/tenant-billing-panel';
import { TenantLicencesPanel } from '@/components/admin/licences/tenant-licences-panel';
import { ActivityPanel } from '@/components/admin/tenants/activity-panel';
import { BranchCard } from '@/components/admin/tenants/branch-card';
import { BranchDialog } from '@/components/admin/tenants/branch-dialog';
import { formatDate, plural } from '@/components/admin/tenants/format';
import { TenantActions } from '@/components/admin/tenants/tenant-actions';
import { TenantDetails } from '@/components/admin/tenants/tenant-details';
import { type TenantBranch, type TenantShowProps } from '@/components/admin/tenants/types';
import { UsersPanel } from '@/components/admin/tenants/users-panel';
import { EmptyState } from '@/components/shared/empty-state';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs, type PageTab } from '@/components/shared/page-tabs';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head } from '@inertiajs/react';
import { CalendarDays, MonitorSmartphone, Plus, Store, Users } from 'lucide-react';
import { useState } from 'react';

type TabValue = 'branches' | 'users' | 'activity' | 'licences' | 'billing';

const TAB_VALUES: TabValue[] = ['branches', 'users', 'activity', 'licences', 'billing'];

function initialTab(): TabValue {
    if (typeof window === 'undefined') {
        return 'branches';
    }
    const tab = new URLSearchParams(window.location.search).get('tab') as TabValue | null;

    return tab && TAB_VALUES.includes(tab) ? tab : 'branches';
}

export default function TenantShow({ tenant, stats, branches, members, activity, nations, roles, maxTills, licensing, plans, billing, can }: TenantShowProps) {
    const [tab, setTab] = useState<TabValue>(initialTab);
    const [branchDialog, setBranchDialog] = useState<{ open: boolean; branch: TenantBranch | null }>({ open: false, branch: null });

    const changeTab = (value: TabValue) => {
        setTab(value);
        const url = new URL(window.location.href);
        url.search = value === 'branches' ? '' : `?tab=${value}`;
        window.history.replaceState(window.history.state, '', url.toString());
    };

    const tabs: PageTab[] = [
        { value: 'branches', label: 'Branches and tills', count: branches.length },
        { value: 'users', label: 'Users', count: members.length },
        { value: 'activity', label: 'Activity' },
        {
            value: 'licences',
            label: 'Licences',
            count: licensing.summary.live,
            badge:
                licensing.summary.missing > 0 ? (
                    <Badge variant="warning" title="Active tills without a licence">
                        {licensing.summary.missing} missing
                    </Badge>
                ) : undefined,
        },
        { value: 'billing', label: 'Billing' },
    ];

    return (
        <AdminLayout breadcrumbs={[{ title: 'Customers' }, { title: 'Tenants', href: route('admin.tenants.index') }, { title: tenant.name }]}>
            <Head title={tenant.name} />

            <PageHeader
                title={tenant.name}
                status={<StatusBadge status={tenant.status} />}
                back={{ href: route('admin.tenants.index'), label: 'Tenants' }}
                media={<InitialsAvatar name={tenant.name} shape="square" size="lg" />}
                description={`${tenant.legalName ?? 'No legal name'} · Customer since ${formatDate(tenant.createdAt)}`}
                actions={<TenantActions tenant={tenant} stats={stats} members={members} canManage={can.manage} canImpersonate={can.impersonate} />}
            />

            <StatGrid>
                <StatCard
                    label="Branches"
                    value={stats.branches}
                    icon={Store}
                    hint={stats.branchesInactive > 0 ? `${stats.branchesInactive} inactive` : undefined}
                />
                <StatCard
                    label="Active tills"
                    value={stats.tills}
                    icon={MonitorSmartphone}
                    tone={licensing.summary.missing > 0 ? 'warning' : 'primary'}
                    hint={
                        licensing.summary.missing > 0
                            ? `${licensing.summary.missing} without a licence`
                            : `${licensing.summary.counts.trial + licensing.summary.counts.active + licensing.summary.counts.grace} trading`
                    }
                />
                <StatCard label="Users" value={stats.users} icon={Users} tone="success" />
                <StatCard
                    label="Created"
                    value={<span className="text-xl sm:text-2xl">{formatDate(tenant.createdAt)}</span>}
                    icon={CalendarDays}
                    tone="neutral"
                    hint={
                        tenant.status === 'trial'
                            ? tenant.trialEndsAt
                                ? `Trial ends ${formatDate(tenant.trialEndsAt)}`
                                : 'Trial starts on first activation'
                            : undefined
                    }
                />
            </StatGrid>

            <TenantDetails tenant={tenant} />

            <div className="flex flex-col gap-5">
                <PageTabs tabs={tabs} value={tab} onChange={(value) => changeTab(value as TabValue)} label="Tenant sections" />

                <div id={`tab-panel-${tab}`} role="tabpanel" aria-labelledby={`tab-${tab}`} className="flex flex-col gap-4">
                    {tab === 'branches' && (
                        <>
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-muted-foreground text-sm">
                                    {plural(stats.branches, 'active branch', 'active branches')} with {plural(stats.tills, 'active till')}. The main
                                    till of each branch syncs with the portal.
                                </p>
                                {can.manage && tenant.status !== 'cancelled' && (
                                    <Button size="sm" onClick={() => setBranchDialog({ open: true, branch: null })}>
                                        <Plus />
                                        Add branch
                                    </Button>
                                )}
                            </div>
                            {branches.length === 0 ? (
                                <EmptyState icon={Store} title="No branches yet" body="Add the customer’s first shop and its tills." bordered />
                            ) : (
                                branches.map((branch) => (
                                    <BranchCard
                                        key={branch.id}
                                        tenantId={tenant.id}
                                        branch={branch}
                                        canManage={can.manage}
                                        tillLicences={licensing.tillLicences}
                                        canManageLicences={can.manageLicences}
                                        onEdit={(selected) => setBranchDialog({ open: true, branch: selected })}
                                    />
                                ))
                            )}
                        </>
                    )}
                    {tab === 'users' && (
                        <UsersPanel tenant={tenant} members={members} roles={roles} canManage={can.manage} canImpersonate={can.impersonate} />
                    )}
                    {tab === 'activity' && <ActivityPanel activity={activity} />}
                    {tab === 'licences' && <TenantLicencesPanel tenant={tenant} licensing={licensing} plans={plans} canManage={can.manageLicences} />}
                    {tab === 'billing' && <TenantBillingPanel tenant={tenant} billing={billing} />}
                </div>
            </div>

            <BranchDialog
                open={branchDialog.open}
                onOpenChange={(open) => setBranchDialog((current) => ({ ...current, open }))}
                tenantId={tenant.id}
                branch={branchDialog.branch}
                nations={nations}
                maxTills={maxTills}
            />
        </AdminLayout>
    );
}
