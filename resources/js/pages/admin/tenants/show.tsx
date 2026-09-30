import { TenantBillingPanel } from '@/components/admin/billing/tenant-billing-panel';
import { TenantCloudPanel } from '@/components/admin/cloud-link/tenant-cloud-panel';
import { BranchLimitsDialog } from '@/components/admin/licences/branch-limits-dialog';
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
import { CalendarDays, MonitorSmartphone, Plus, SlidersHorizontal, Store, Users } from 'lucide-react';
import { useState } from 'react';

type TabValue = 'branches' | 'users' | 'activity' | 'licences' | 'cloud' | 'billing';

const TAB_VALUES: TabValue[] = ['branches', 'users', 'activity', 'licences', 'cloud', 'billing'];

function initialTab(canBill: boolean): TabValue {
    if (typeof window === 'undefined') {
        return 'branches';
    }
    const tab = new URLSearchParams(window.location.search).get('tab') as TabValue | null;

    return tab && TAB_VALUES.includes(tab) && (tab !== 'billing' || canBill) ? tab : 'branches';
}

export default function TenantShow({
    tenant,
    stats,
    branches,
    members,
    activity,
    nations,
    roles,
    maxTills,
    licensing,
    plans,
    billing,
    branchLimits,
    licenceOptions,
    cloudLink,
    can,
}: TenantShowProps) {
    const [tab, setTab] = useState<TabValue>(() => initialTab(billing !== null));
    const [branchDialog, setBranchDialog] = useState<{ open: boolean; branch: TenantBranch | null }>({ open: false, branch: null });
    const [limitsOpen, setLimitsOpen] = useState(false);
    // Module 1.11: no branch past the branches allowed (one without multi-branch).
    const branchesFull = branchLimits.branchesInUse >= branchLimits.branchesAllowed;

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
        // Module 2.8: moves to the cloud and reported dealer keys.
        {
            value: 'cloud',
            label: 'Cloud link',
            count: cloudLink.moves.length + cloudLink.keys.length || undefined,
            badge: cloudLink.keys.some((key) => key.refusedCount > 0) ? <Badge variant="danger">Key on two PCs</Badge> : undefined,
        },
        // Billing is for owner and accounts only (billing.manage): no data, no tab.
        ...(billing ? [{ value: 'billing', label: 'Billing' } satisfies PageTab] : []),
    ];

    return (
        <AdminLayout>
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
                                <div className="grid gap-1">
                                    <p className="text-muted-foreground text-sm">
                                        {plural(stats.branches, 'active branch', 'active branches')} with {plural(stats.tills, 'active till')}. The
                                        main till of each branch syncs with the portal.
                                    </p>
                                    <p className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-medium tabular-nums">
                                            {branchLimits.branchesInUse} of {branchLimits.branchesAllowed}{' '}
                                            {branchLimits.branchesAllowed === 1 ? 'branch' : 'branches'} allowed in use
                                        </span>
                                        <Badge variant={branchLimits.multiBranch ? 'success' : 'neutral'}>
                                            Multi-branch {branchLimits.multiBranch ? 'on' : 'off'}
                                        </Badge>
                                    </p>
                                </div>
                                <div className="flex shrink-0 flex-wrap gap-2">
                                    {can.manageLicences && (
                                        <Button size="sm" variant="outline" onClick={() => setLimitsOpen(true)}>
                                            <SlidersHorizontal />
                                            Branch limits
                                        </Button>
                                    )}
                                    {can.manage && tenant.status !== 'cancelled' && (
                                        <Button
                                            size="sm"
                                            disabled={branchesFull}
                                            title={
                                                branchesFull
                                                    ? branchLimits.multiBranch
                                                        ? 'All branches allowed are in use. Raise the branches allowed first.'
                                                        : 'Licensed for one branch. Turn on multi-branch first.'
                                                    : undefined
                                            }
                                            onClick={() => setBranchDialog({ open: true, branch: null })}
                                        >
                                            <Plus />
                                            Add branch
                                        </Button>
                                    )}
                                </div>
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
                                        licenceOptions={licenceOptions}
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
                    {tab === 'cloud' && <TenantCloudPanel moves={cloudLink.moves} keys={cloudLink.keys} canClear={can.manageLicences} />}
                    {tab === 'billing' && billing && <TenantBillingPanel tenant={tenant} billing={billing} />}
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
            <BranchLimitsDialog
                open={limitsOpen}
                onOpenChange={setLimitsOpen}
                tenantId={tenant.id}
                tenantName={tenant.name}
                limits={branchLimits}
                maxBranches={licenceOptions.maxBranches}
            />
        </AdminLayout>
    );
}
