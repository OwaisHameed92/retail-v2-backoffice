import { DashboardTabs } from '@/components/admin/dashboard/dashboard-tabs';
import { RecentTenantsCard } from '@/components/admin/dashboard/recent-tenants-card';
import { TillHealthTile } from '@/components/admin/dashboard/till-health-tile';
import { dashboardRangeLabel, dashboardRanges, RevenueCard } from '@/components/admin/dashboard/revenue-card';
import { type AdminDashboardProps, type DashboardFigure, type DashboardRange, type RevenueChartData } from '@/components/admin/dashboard/types';
import { type AdminSharedData } from '@/components/admin/types';
import { AttentionList } from '@/components/shared/attention-list';
import { HealthList, type HealthItem } from '@/components/shared/health-list';
import { KpiCard, KpiGrid } from '@/components/shared/kpi-card';
import { OverviewTile } from '@/components/shared/overview-tile';
import { QuickActions, type QuickAction } from '@/components/shared/quick-actions';
import { type ChartTone } from '@/components/shared/trend-chart';
import { WelcomeBanner } from '@/components/shared/welcome-banner';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    Coins,
    Database,
    FileText,
    FileWarning,
    KeyRound,
    Lock,
    Mail,
    Monitor,
    MoreHorizontal,
    Plus,
    RefreshCw,
    Server,
    Timer,
    UsersRound,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

type KpiKey = keyof AdminDashboardProps['kpis'];

interface Kpi {
    key: KpiKey;
    label: string;
    icon: LucideIcon;
    tone: ChartTone;
    goodWhen?: 'up' | 'down';
    link?: { label: string; route: string; ability: string };
}

const kpis: Kpi[] = [
    { key: 'revenue', label: 'Monthly revenue', icon: Coins, tone: 'primary', link: { label: 'Open billing', route: 'admin.billing.index', ability: 'billing.manage' } },
    { key: 'activeTills', label: 'Active tills', icon: Monitor, tone: 'info', link: { label: 'View licences', route: 'admin.licences.index', ability: 'tenants.view' } },
    { key: 'trials', label: 'Trials running', icon: UsersRound, tone: 'violet', link: { label: 'View tenants', route: 'admin.tenants.index', ability: 'tenants.view' } },
    {
        key: 'overdue',
        label: 'Overdue',
        icon: FileWarning,
        tone: 'danger',
        link: { label: 'View invoices', route: 'admin.billing.invoices.index', ability: 'billing.manage' },
    },
];

const healthIcons: Record<string, LucideIcon> = {
    database: Database,
    queue: Server,
    scheduler: Timer,
    signing: KeyRound,
    email: Mail,
    tills: RefreshCw,
};

function LockedHint() {
    return (
        <span className="inline-flex items-center gap-1">
            <Lock className="size-3" aria-hidden />
            Needs billing access
        </span>
    );
}

function KpiMenu({ label, href, linkLabel }: { label: string; href: string; linkLabel: string }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="text-muted-foreground size-8" aria-label={`${label} options`}>
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem asChild>
                    <Link href={href}>{linkLabel}</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The figure's delta without nulls, as the shared cards expect. */
const deltaOf = (figure: DashboardFigure) => figure.delta ?? undefined;

export default function AdminDashboard({
    dashboard,
    revenue,
    range,
}: {
    dashboard: AdminDashboardProps;
    revenue: RevenueChartData | null;
    range: DashboardRange;
}) {
    const { admin } = usePage<AdminSharedData>().props;
    const can = (ability: string) => admin.abilities.includes(ability);
    const [loadingRange, setLoadingRange] = useState(false);

    const changeRange = (next: DashboardRange) => {
        if (next === range) {
            return;
        }
        router.reload({
            data: { range: next },
            only: ['revenue', 'range'],
            onStart: () => setLoadingRange(true),
            onFinish: () => setLoadingRange(false),
        });
    };

    const actions: QuickAction[] = [
        ...(can('tenants.manage') ? [{ label: 'New tenant', icon: Plus, href: route('admin.tenants.create'), primary: true }] : []),
        ...(can('tenants.view') ? [{ label: 'View licences', icon: KeyRound, href: route('admin.licences.index') }] : []),
        ...(can('billing.manage') ? [{ label: 'Create invoice', icon: FileText, href: route('admin.billing.invoices.index') }] : []),
        ...(can('licences.manage') ? [{ label: 'Send test email', icon: Mail, href: route('admin.emails.templates') }] : []),
    ];

    const tillHealthHref = can('tenants.view') ? route('admin.till-health.index') : undefined;
    const health: HealthItem[] = dashboard.health.map((item) => ({
        name: item.name,
        icon: healthIcons[item.key] ?? Server,
        state: item.state,
        detail: item.detail,
        href: item.key === 'tills' ? tillHealthHref : undefined,
    }));

    return (
        <AdminLayout>
            <Head title="Admin dashboard" />

            <WelcomeBanner
                name={admin.name.split(' ')[0]}
                subtitle="Here's what's happening with Switch & Save customers today."
                actions={
                    <>
                        {dashboard.access.billing && (
                            <Select value={range} onValueChange={(value) => changeRange(value as DashboardRange)}>
                                <SelectTrigger className="bg-card h-10 w-44" aria-label="Revenue period">
                                    <CalendarDays className="text-muted-foreground size-4" aria-hidden />
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    {dashboardRanges.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {dashboardRangeLabel[option.value]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        {can('tenants.manage') && (
                            <Button size="lg" asChild>
                                <Link href={route('admin.tenants.create')}>
                                    <Plus />
                                    New tenant
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <DashboardTabs active="overview" />

            <KpiGrid>
                {kpis.map((kpi) => {
                    const figure = dashboard.kpis[kpi.key];

                    return (
                        <KpiCard
                            key={kpi.key}
                            label={kpi.label}
                            icon={kpi.icon}
                            tone={kpi.tone}
                            value={figure.value}
                            delta={deltaOf(figure)}
                            series={figure.series}
                            footer={figure.footer}
                            emptyText={figure.locked ? <LockedHint /> : undefined}
                            menu={
                                kpi.link && can(kpi.link.ability) && <KpiMenu label={kpi.label} href={route(kpi.link.route)} linkLabel={kpi.link.label} />
                            }
                        />
                    );
                })}
            </KpiGrid>

            <div className="grid gap-4 xl:grid-cols-12">
                <div className="flex min-w-0 flex-col gap-4 xl:col-span-7">
                    <RevenueCard chart={revenue} range={range} loading={loadingRange} onRangeChange={changeRange} />
                    <RecentTenantsCard tenants={dashboard.recentTenants} viewAllHref={can('tenants.view') ? route('admin.tenants.index') : undefined} />
                </div>

                <div className="flex min-w-0 flex-col gap-4 xl:col-span-5">
                    <AttentionList
                        items={dashboard.attention.items}
                        total={dashboard.attention.total}
                        emptyBody="Trials ending in the next 2 days, licence alerts, overdue invoices and late lead follow-ups show here."
                    />

                    <Card className="flex flex-col gap-3 p-5">
                        <div className="flex items-center gap-3">
                            <Building2 className="text-primary size-5" aria-hidden />
                            <h2 className="text-foreground flex-1 text-base font-semibold tracking-tight">Business overview</h2>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <OverviewTile
                                label="Total tenants"
                                icon={Building2}
                                tone="primary"
                                value={dashboard.overview.tenants.value}
                                delta={deltaOf(dashboard.overview.tenants)}
                            />
                            <OverviewTile
                                label="Total revenue (12W)"
                                icon={Coins}
                                tone="info"
                                value={dashboard.overview.revenue.value}
                                delta={deltaOf(dashboard.overview.revenue)}
                                emptyText={dashboard.overview.revenue.locked ? <LockedHint /> : undefined}
                            />
                        </div>
                    </Card>

                    <TillHealthTile summary={dashboard.tills} href={tillHealthHref} />

                    <QuickActions actions={actions} />

                    <HealthList items={health} />
                </div>
            </div>
        </AdminLayout>
    );
}
