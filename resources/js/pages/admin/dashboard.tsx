import { type AdminSharedData } from '@/components/admin/types';
import { AttentionList } from '@/components/shared/attention-list';
import { ChartCard, SegmentedControl, type SegmentOption } from '@/components/shared/chart-card';
import { EmptyState } from '@/components/shared/empty-state';
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
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    Building2,
    CalendarDays,
    Coins,
    FileText,
    FileWarning,
    KeyRound,
    Mail,
    Monitor,
    MoreHorizontal,
    Plus,
    PoundSterling,
    RefreshCw,
    Server,
    UsersRound,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

type Range = '12w' | '6m' | '1y';

const ranges: SegmentOption<Range>[] = [
    { value: '12w', label: '12W' },
    { value: '6m', label: '6M' },
    { value: '1y', label: '1Y' },
];

const rangeLabel: Record<Range, string> = { '12w': 'Last 12 weeks', '6m': 'Last 6 months', '1y': 'Last 12 months' };

interface Kpi {
    label: string;
    icon: LucideIcon;
    tone: ChartTone;
    link?: { label: string; route: string; ability: string };
}

// Live figures arrive with module 1.9 (admin dashboard); until then every KPI shows "—" and "No data yet".
const kpis: Kpi[] = [
    {
        label: 'Monthly revenue',
        icon: Coins,
        tone: 'primary',
        link: { label: 'Open billing', route: 'admin.billing.index', ability: 'tenants.view' },
    },
    { label: 'Active tills', icon: Monitor, tone: 'info', link: { label: 'View licences', route: 'admin.licences.index', ability: 'tenants.view' } },
    {
        label: 'Trials running',
        icon: UsersRound,
        tone: 'violet',
        link: { label: 'View tenants', route: 'admin.tenants.index', ability: 'tenants.view' },
    },
    {
        label: 'Overdue',
        icon: FileWarning,
        tone: 'danger',
        link: { label: 'View invoices', route: 'admin.billing.invoices.index', ability: 'tenants.view' },
    },
];

const health: HealthItem[] = [
    { name: 'Licence API', icon: KeyRound, state: 'unknown' },
    { name: 'Till sync', icon: RefreshCw, state: 'unknown' },
    { name: 'Email delivery', icon: Mail, state: 'unknown' },
    { name: 'Background jobs', icon: Server, state: 'unknown' },
];

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

export default function AdminDashboard() {
    const { admin } = usePage<AdminSharedData>().props;
    const can = (ability: string) => admin.abilities.includes(ability);
    const [range, setRange] = useState<Range>('12w');

    const actions: QuickAction[] = [
        ...(can('tenants.manage') ? [{ label: 'New tenant', icon: Plus, href: route('admin.tenants.create'), primary: true }] : []),
        ...(can('tenants.view') ? [{ label: 'View licences', icon: KeyRound, href: route('admin.licences.index') }] : []),
        ...(can('billing.manage') ? [{ label: 'Create invoice', icon: FileText, href: route('admin.billing.invoices.index') }] : []),
        ...(can('licences.manage') ? [{ label: 'Send test email', icon: Mail, href: route('admin.emails.templates') }] : []),
    ];

    return (
        <AdminLayout>
            <Head title="Admin dashboard" />

            <WelcomeBanner
                name={admin.name.split(' ')[0]}
                subtitle="Here's what's happening with Switch & Save customers today."
                actions={
                    <>
                        <Select value={range} onValueChange={(value) => setRange(value as Range)}>
                            <SelectTrigger className="bg-card h-10 w-44" aria-label="Period">
                                <CalendarDays className="text-muted-foreground size-4" aria-hidden />
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent align="end">
                                {ranges.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {rangeLabel[option.value]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
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

            <KpiGrid>
                {kpis.map((kpi) => (
                    <KpiCard
                        key={kpi.label}
                        label={kpi.label}
                        icon={kpi.icon}
                        tone={kpi.tone}
                        value={null}
                        menu={
                            kpi.link && can(kpi.link.ability) && <KpiMenu label={kpi.label} href={route(kpi.link.route)} linkLabel={kpi.link.label} />
                        }
                    />
                ))}
            </KpiGrid>

            <div className="grid gap-4 xl:grid-cols-12">
                <div className="flex min-w-0 flex-col gap-4 xl:col-span-7">
                    <ChartCard
                        title="Revenue"
                        subtitle={rangeLabel[range]}
                        icon={BarChart3}
                        controls={<SegmentedControl label="Revenue range" options={ranges} value={range} onChange={setRange} />}
                    >
                        <EmptyState
                            icon={PoundSterling}
                            title="No revenue data yet"
                            body="Paid invoices will chart here week by week once live dashboard figures are switched on."
                            size="sm"
                            bordered
                        />
                    </ChartCard>

                    <Card className="flex flex-col overflow-clip">
                        <div className="flex items-center gap-3 px-5 pt-5 pb-3">
                            <Building2 className="text-primary size-5" aria-hidden />
                            <h2 className="text-foreground flex-1 text-base font-semibold tracking-tight">Recent tenants</h2>
                            {can('tenants.view') && (
                                <Link
                                    href={route('admin.tenants.index')}
                                    className="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline"
                                >
                                    View all
                                    <ArrowRight className="size-4" aria-hidden />
                                </Link>
                            )}
                        </div>
                        <div className="border-t">
                            <EmptyState
                                icon={Building2}
                                title="No recent activity yet"
                                body="The latest tenants, their plan, tills and MRR will list here. Until then, open Tenants for every business."
                                size="sm"
                            />
                        </div>
                    </Card>
                </div>

                <div className="flex min-w-0 flex-col gap-4 xl:col-span-5">
                    <AttentionList items={[]} />

                    <Card className="flex flex-col gap-3 p-5">
                        <div className="flex items-center gap-3">
                            <Building2 className="text-primary size-5" aria-hidden />
                            <h2 className="text-foreground flex-1 text-base font-semibold tracking-tight">Business overview</h2>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <OverviewTile label="Total tenants" icon={Building2} tone="primary" value={null} />
                            <OverviewTile
                                label={`Total revenue (${ranges.find((r) => r.value === range)?.label})`}
                                icon={Coins}
                                tone="info"
                                value={null}
                            />
                        </div>
                    </Card>

                    <QuickActions actions={actions} />

                    <HealthList items={health} />
                </div>
            </div>
        </AdminLayout>
    );
}
