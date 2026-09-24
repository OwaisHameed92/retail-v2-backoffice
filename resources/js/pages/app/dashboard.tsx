import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { PoundSterling, Receipt, RefreshCw, ShoppingBasket, TrendingUp } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/app' }];

const stats = [
    { label: 'Sales', icon: PoundSterling },
    { label: 'Transactions', icon: Receipt },
    { label: 'Avg basket', icon: ShoppingBasket },
    { label: 'Gross profit', icon: TrendingUp },
];

export default function Dashboard() {
    const { company } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <PageHeader title="Dashboard" description={`How ${company?.name ?? 'your business'} is trading today, across all branches.`} />

            <StatGrid>
                {stats.map((stat) => (
                    <StatCard
                        key={stat.label}
                        label={stat.label}
                        value={<span aria-label={`${stat.label}: no data yet`}>—</span>}
                        hint="Waiting for the first sync"
                        icon={stat.icon}
                    />
                ))}
            </StatGrid>

            <SectionCard title="Sales today" description="Takings by hour, per branch." className="flex-1">
                <EmptyState
                    icon={RefreshCw}
                    title="Nothing to show yet"
                    body="Your dashboard fills up once your tills start syncing. Sales, baskets and profit appear here within a minute of each sale."
                />
            </SectionCard>
        </AppLayout>
    );
}
