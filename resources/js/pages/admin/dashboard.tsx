import { type AdminSharedData } from '@/components/admin/types';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid, type StatTone } from '@/components/shared/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, Building2, Clock, Inbox, KeyRound, PoundSterling, Timer, type LucideIcon } from 'lucide-react';

const stats: { title: string; icon: LucideIcon; tone: StatTone }[] = [
    { title: 'Tenants', icon: Building2, tone: 'primary' },
    { title: 'Trials', icon: Clock, tone: 'warning' },
    { title: 'Licences', icon: KeyRound, tone: 'success' },
    { title: 'Cash due', icon: PoundSterling, tone: 'danger' },
];

function greeting(): string {
    const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hour12: false, timeZone: 'Europe/London' }).format(new Date()));

    return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
}

function SoonCard({ title, description, icon, body }: { title: string; description: string; icon: LucideIcon; body: string }) {
    return (
        <SectionCard title={title} description={description} actions={<Badge variant="neutral">Soon</Badge>}>
            <EmptyState icon={icon} title="Nothing here yet" body={body} size="sm" />
        </SectionCard>
    );
}

export default function AdminDashboard() {
    const { admin } = usePage<AdminSharedData>().props;
    const canSeeTenants = admin.abilities.includes('tenants.view');

    return (
        <AdminLayout>
            <Head title="Admin dashboard" />

            <PageHeader
                title={`${greeting()}, ${admin.name.split(' ')[0]}`}
                description="Here is how Switch & Save customers are doing today."
                actions={
                    canSeeTenants && (
                        <Button variant="outline" asChild>
                            <Link href={route('admin.tenants.index')}>
                                <Building2 />
                                View tenants
                            </Link>
                        </Button>
                    )
                }
            />

            <StatGrid>
                {stats.map((stat) => (
                    <StatCard
                        key={stat.title}
                        label={stat.title}
                        value={<span className="text-muted-foreground">—</span>}
                        hint="Live figures coming soon"
                        icon={stat.icon}
                        tone={stat.tone}
                    />
                ))}
            </StatGrid>

            <div className="grid gap-6 lg:grid-cols-2">
                <SoonCard
                    title="New leads"
                    description="Trial requests from the website, newest first."
                    icon={Inbox}
                    body="New trial requests will be listed here with one-click approval."
                />
                <SoonCard
                    title="Trials ending soon"
                    description="Customers whose free trial ends in the next 7 days."
                    icon={Timer}
                    body="Trials that need a follow-up call will appear here."
                />
            </div>

            <SoonCard
                title="Till health"
                description="Tills that have not checked in, or are running an old EPOS version."
                icon={Activity}
                body="Offline tills and version warnings will show here once tills report in."
            />
        </AdminLayout>
    );
}
