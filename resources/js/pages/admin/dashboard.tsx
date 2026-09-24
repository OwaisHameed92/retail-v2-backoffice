import { PageHeader } from '@/components/shared/page-header';
import { type AdminSharedData } from '@/components/admin/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import { Head, usePage } from '@inertiajs/react';
import { Building2, Clock, KeyRound, LayoutDashboard, PoundSterling, type LucideIcon } from 'lucide-react';

const stats: { title: string; icon: LucideIcon }[] = [
    { title: 'Tenants', icon: Building2 },
    { title: 'Trials', icon: Clock },
    { title: 'Licences', icon: KeyRound },
    { title: 'Cash due', icon: PoundSterling },
];

export default function AdminDashboard() {
    const { admin } = usePage<AdminSharedData>().props;

    return (
        <AdminLayout>
            <Head title="Admin dashboard" />

            <PageHeader title="Dashboard" description={`Welcome back, ${admin.name}.`} />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map((stat) => (
                    <Card key={stat.title} className="gap-0">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">{stat.title}</CardTitle>
                            <stat.icon className="text-muted-foreground size-4" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-muted-foreground text-2xl font-semibold">—</div>
                            <p className="text-muted-foreground text-xs">No data yet</p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed px-6 py-16 text-center">
                <div className="bg-muted mb-4 flex size-12 items-center justify-center rounded-full">
                    <LayoutDashboard className="text-muted-foreground size-6" />
                </div>
                <h2 className="text-base font-medium">Your dashboard is on its way</h2>
                <p className="text-muted-foreground mt-1 max-w-md text-sm">
                    Tenant, trial, licence and cash numbers, new leads and trials ending soon arrive in module 1.9.
                </p>
            </div>
        </AdminLayout>
    );
}
