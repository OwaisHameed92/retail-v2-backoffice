import { reportQuery, reportUrl } from '@/components/app/reports/format';
import { type ReportIndexProps, type ReportKey } from '@/components/app/reports/types';
import { PageHeader } from '@/components/shared/page-header';
import { toneCircle, type ChartTone } from '@/components/shared/trend-chart';
import AppLayout from '@/layouts/app-layout';
import { taxName } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Banknote, Boxes, ChevronRight, Clock, CreditCard, type LucideIcon, Package, Percent, Receipt, RotateCcw, Tag, Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/app/reports' }];

const ICONS: Record<ReportKey, { icon: LucideIcon; tone: ChartTone }> = {
    sales: { icon: Receipt, tone: 'primary' },
    products: { icon: Package, tone: 'primary' },
    refunds: { icon: RotateCcw, tone: 'danger' },
    discounts: { icon: Tag, tone: 'warning' },
    vat: { icon: Percent, tone: 'violet' },
    tenders: { icon: CreditCard, tone: 'info' },
    staff: { icon: Users, tone: 'success' },
    hourly: { icon: Clock, tone: 'info' },
    stock: { icon: Boxes, tone: 'warning' },
    shifts: { icon: Banknote, tone: 'success' },
};

/**
 * The reports hub (module 4.8): every report by job. Links keep the shop (the portal switcher) and till; each report
 * opens on the last 7 days and can be exported as CSV or printed.
 */
export default function ReportsIndex({ reports, filters, context }: ReportIndexProps) {
    const { company } = usePage<SharedData>().props;
    const sections = [...new Set(reports.map((r) => r.section))];
    const query = reportQuery({ ...filters, view: '', page: 1 });
    const where = context.branch ? context.branch.name : `every shop of ${company?.name ?? 'your business'}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reports" />

            <PageHeader
                title="Reports"
                description={`Sales, ${taxName()}, payments, staff, stock and cash for ${where}. Every report can be filtered by dates and shop, exported as CSV and printed.`}
            />

            {sections.map((section) => (
                <section key={section} aria-labelledby={`reports-${section}`} className="flex flex-col gap-3">
                    <h2 id={`reports-${section}`} className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
                        {section}
                    </h2>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {reports
                            .filter((r) => r.section === section)
                            .map((r) => {
                                const { icon: Icon, tone } = ICONS[r.value];

                                return (
                                    <Link
                                        key={r.value}
                                        href={reportUrl('app.reports.show', r.value, query)}
                                        className="bg-card shadow-card rounded-card group focus-visible:ring-ring/35 flex flex-col gap-3 border p-5 transition-colors outline-none hover:border-[var(--border-strong)] focus-visible:ring-[3px]"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className={cn('flex size-10 items-center justify-center rounded-full', toneCircle[tone])}>
                                                <Icon className="size-5" aria-hidden />
                                            </span>
                                            <ChevronRight className="text-muted-foreground size-4 transition-transform group-hover:translate-x-0.5" aria-hidden />
                                        </div>
                                        <div>
                                            <p className="text-foreground font-semibold tracking-tight">{r.label}</p>
                                            <p className="text-muted-foreground mt-1 text-sm">{r.description}</p>
                                        </div>
                                    </Link>
                                );
                            })}
                    </div>
                </section>
            ))}
        </AppLayout>
    );
}
