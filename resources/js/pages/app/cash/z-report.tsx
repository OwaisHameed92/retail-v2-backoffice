import { AlertFlag, formatDateTime, formatDay, number, Variance } from '@/components/app/cash/format';
import { ZTendersTable } from '@/components/app/cash/shift-sections';
import { type ZReportProps } from '@/components/app/cash/types';
import { DescriptionList } from '@/components/shared/description-list';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';

/** One Z report (module 5.4): the till's own totals, read only. The printable Z is on the till. */
export default function CashZReport({ report }: ZReportProps) {
    const where = [report.shop, report.till].filter(Boolean).join(' · ');

    return (
        <AppLayout>
            <Head title={`Z ${report.sequenceNo}`} />
            <PageHeader
                title={`Z report ${report.sequenceNo}`}
                back={{ href: route('app.cash.z.index'), label: 'Z reports' }}
                status={report.totals.warning ? <AlertFlag label="Till alert" /> : undefined}
                description={`${where}${where ? ' · ' : ''}${formatDay(report.day)}`}
                actions={
                    report.shiftId && (
                        <Button variant="outline" asChild>
                            <Link href={route('app.cash.shifts.show', report.shiftId)}>
                                <Clock />
                                View the shift
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
                <SectionCard
                    className="lg:col-span-2"
                    title="Totals by payment type"
                    description="As the till printed them. Variance = declared − expected: negative is short."
                    actions={<Variance value={report.totals.variance} className="text-lg" />}
                    flush
                >
                    <ZTendersTable totals={report.totals} />
                </SectionCard>
                <SectionCard title="Details">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Period from', value: formatDateTime(report.periodStart) },
                            { label: 'Period to', value: formatDateTime(report.periodEnd) },
                            { label: 'Generated', value: formatDateTime(report.generatedAt) },
                            { label: 'Printed', value: report.printedAt ? formatDateTime(report.printedAt) : null },
                            { label: 'Printed by', value: report.printedBy },
                            { label: 'Reprints', value: number(report.reprints) },
                        ]}
                    />
                </SectionCard>
            </div>
        </AppLayout>
    );
}
