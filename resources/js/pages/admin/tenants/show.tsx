import { ActivityPanel } from '@/components/admin/tenants/activity-panel';
import { BranchCard } from '@/components/admin/tenants/branch-card';
import { BranchDialog } from '@/components/admin/tenants/branch-dialog';
import { formatDate, plural } from '@/components/admin/tenants/format';
import { Tabs, type TabItem } from '@/components/admin/tenants/tabs';
import { TenantActions } from '@/components/admin/tenants/tenant-actions';
import { TenantDetails } from '@/components/admin/tenants/tenant-details';
import { type TenantBranch, type TenantShowProps } from '@/components/admin/tenants/types';
import { UsersPanel } from '@/components/admin/tenants/users-panel';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, KeyRound, MonitorSmartphone, Plus, Receipt, Store, Users } from 'lucide-react';
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

function Soon({ icon, title, module }: { icon: typeof KeyRound; title: string; module: string }) {
    return (
        <Card>
            <EmptyState
                icon={icon}
                title={title}
                body={
                    <>
                        Arrives in module {module}. <span className="sr-only">Not available yet.</span>
                    </>
                }
                action={
                    <span className="text-muted-foreground rounded-full border px-2 py-0.5 text-xs" aria-hidden>
                        Soon
                    </span>
                }
            />
        </Card>
    );
}

export default function TenantShow({ tenant, stats, branches, members, activity, nations, roles, maxTills, can }: TenantShowProps) {
    const [tab, setTab] = useState<TabValue>(initialTab);
    const [branchDialog, setBranchDialog] = useState<{ open: boolean; branch: TenantBranch | null }>({ open: false, branch: null });

    const changeTab = (value: TabValue) => {
        setTab(value);
        const url = new URL(window.location.href);
        url.search = value === 'branches' ? '' : `?tab=${value}`;
        window.history.replaceState(window.history.state, '', url.toString());
    };

    const tabs: TabItem<TabValue>[] = [
        { value: 'branches', label: 'Branches and tills', count: branches.length },
        { value: 'users', label: 'Users', count: members.length },
        { value: 'activity', label: 'Activity' },
        { value: 'licences', label: 'Licences' },
        { value: 'billing', label: 'Billing' },
    ];

    return (
        <AdminLayout>
            <Head title={tenant.name} />

            <div>
                <Link
                    href={route('admin.tenants.index')}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    Tenants
                </Link>
            </div>

            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="truncate text-xl font-semibold tracking-tight">{tenant.name}</h1>
                        <StatusBadge status={tenant.status} />
                    </div>
                    <p className="text-muted-foreground text-sm">
                        {tenant.legalName ?? 'No legal name'} · Customer since {formatDate(tenant.createdAt)}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <TenantActions tenant={tenant} stats={stats} members={members} canManage={can.manage} canImpersonate={can.impersonate} />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard
                    label="Branches"
                    value={stats.branches}
                    icon={Store}
                    hint={stats.branchesInactive > 0 ? `${stats.branchesInactive} inactive` : undefined}
                />
                <StatCard label="Active tills" value={stats.tills} icon={MonitorSmartphone} hint="One licence each" />
                <StatCard label="Users" value={stats.users} icon={Users} />
                <StatCard
                    label="Created"
                    value={<span className="text-xl">{formatDate(tenant.createdAt)}</span>}
                    icon={CalendarDays}
                    hint={
                        tenant.status === 'trial'
                            ? tenant.trialEndsAt
                                ? `Trial ends ${formatDate(tenant.trialEndsAt)}`
                                : 'Trial starts on first activation'
                            : undefined
                    }
                />
            </div>

            <TenantDetails tenant={tenant} />

            <div className="flex flex-col gap-4">
                <Tabs tabs={tabs} value={tab} onChange={changeTab} label="Tenant sections" />

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
                                <Card>
                                    <EmptyState icon={Store} title="No branches yet" body="Add the customer’s first shop and its tills." />
                                </Card>
                            ) : (
                                branches.map((branch) => (
                                    <BranchCard
                                        key={branch.id}
                                        tenantId={tenant.id}
                                        branch={branch}
                                        canManage={can.manage}
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
                    {tab === 'licences' && <Soon icon={KeyRound} title="Licences" module="1.3" />}
                    {tab === 'billing' && <Soon icon={Receipt} title="Billing" module="1.8" />}
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
