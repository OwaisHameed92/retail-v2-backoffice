import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/app' }];

const stats = ['Sales', 'Transactions', 'Avg basket', 'Gross profit'];

export default function Dashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {stats.map((label) => (
                        <Card key={label}>
                            <CardHeader className="p-4 pb-1">
                                <CardTitle className="text-muted-foreground text-sm font-medium">{label}</CardTitle>
                            </CardHeader>
                            <CardContent className="p-4 pt-0">
                                <p className="text-2xl font-semibold tabular-nums" aria-label={`${label}: no data yet`}>
                                    —
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-16 text-center">
                    <div className="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-full">
                        <RefreshCw className="size-5" />
                    </div>
                    <h2 className="text-base font-medium">Nothing to show yet</h2>
                    <p className="text-muted-foreground max-w-sm text-sm">Your dashboard fills up once your tills start syncing</p>
                </div>
            </div>
        </AppLayout>
    );
}
