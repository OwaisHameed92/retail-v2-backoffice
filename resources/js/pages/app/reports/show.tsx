import { BusinessFiltersBar, type BusinessQuery } from '@/components/app/dashboard/business-filters';
import { reportQuery, reportUrl } from '@/components/app/reports/format';
import { HeatmapCard, SeriesChartCard } from '@/components/app/reports/report-chart';
import { ReportSummary } from '@/components/app/reports/report-summary';
import { ReportTableCard } from '@/components/app/reports/report-table';
import { type ReportFilters, type ReportGrouping, type ReportShowProps } from '@/components/app/reports/types';
import { SegmentedControl } from '@/components/shared/chart-card';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { SectionCard } from '@/components/shared/section-card';
import { dayRange } from '@/components/shared/trading/format';
import { Freshness } from '@/components/shared/trading/freshness';
import { type TradingCompare, type TradingPeriod } from '@/components/shared/trading/types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Boxes, Download, FileBarChart, Info, Printer } from 'lucide-react';
import { useState } from 'react';

const RELOAD = ['filters', 'context', 'result', 'freshness'];

/**
 * One report (module 4.8): filters (dates, compare, shop from the portal switcher, till), day / week / month where it
 * groups, its tabs, headline figures against the compare window, a chart, its tables, CSV export and a printable view.
 */
export default function ReportShow(props: ReportShowProps) {
    const { report, reports, filters, context, periods, compares, groupings, result, freshness } = props;
    const { company } = usePage<SharedData>().props;
    const [loading, setLoading] = useState(false);

    const visit = (next: Partial<ReportFilters>, keepPage = false) =>
        router.get(route('app.reports.show', report.value), reportQuery(filters, { ...next, page: keepPage ? (next.page ?? filters.page) : 1 }), {
            only: RELOAD,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    // The dashboard's filter bar leaves its defaults (today, previous period, every till) out of its query.
    const fromBar = (q: BusinessQuery) =>
        visit({
            period: (q.period ?? 'today') as TradingPeriod,
            compare: (q.compare ?? 'previousPeriod') as TradingCompare,
            from: q.from ?? filters.from,
            to: q.to ?? filters.to,
            till: q.till ?? null,
        });

    const query = reportQuery(filters);
    const where = context.till
        ? `${context.till.label} at ${context.branch?.name}`
        : (context.branch?.name ?? `All shops of ${company?.name ?? 'your business'}`);
    const versus =
        report.compares && filters.compare !== 'none' ? (compares.find((c) => c.value === filters.compare)?.label.toLowerCase() ?? '') : '';
    const sections = [...new Set(reports.map((r) => r.section))];

    return (
        <AppLayout>
            <Head title={report.label} />

            <PageHeader
                title={report.label}
                back={{ href: route('app.reports.index'), label: 'Reports' }}
                description={report.usesDates ? `${where}, ${dayRange(filters.from, filters.to)}.` : `${where}, stock now.`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {freshness && <Freshness info={freshness} loading={loading} />}
                        <Button variant="outline" asChild>
                            <a href={reportUrl('app.reports.export', report.value, { ...query, page: undefined })} download>
                                <Download className="size-4" aria-hidden />
                                Export CSV
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={reportUrl('app.reports.print', report.value, { ...query, page: undefined })} target="_blank" rel="noopener">
                                <Printer className="size-4" aria-hidden />
                                Print
                            </a>
                        </Button>
                    </div>
                }
            />

            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <Select
                        value={report.value}
                        onValueChange={(value) => router.get(reportUrl('app.reports.show', value, { ...query, view: undefined, page: undefined }))}
                    >
                        <SelectTrigger className="bg-card h-9 w-full sm:w-60" aria-label="Report">
                            <FileBarChart className="text-muted-foreground size-4" aria-hidden />
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {sections.map((section) => (
                                <SelectGroup key={section}>
                                    <SelectLabel>{section}</SelectLabel>
                                    {reports
                                        .filter((r) => r.section === section)
                                        .map((r) => (
                                            <SelectItem key={r.value} value={r.value}>
                                                {r.label}
                                            </SelectItem>
                                        ))}
                                </SelectGroup>
                            ))}
                        </SelectContent>
                    </Select>
                    <BusinessFiltersBar
                        filters={filters}
                        context={context}
                        periods={periods}
                        compares={compares}
                        onChange={fromBar}
                        onLoading={setLoading}
                        showDates={report.usesDates}
                        showCompare={report.compares}
                    />
                </div>
                {report.groups && (
                    <SegmentedControl<ReportGrouping>
                        label="Group by"
                        value={filters.group}
                        onChange={(group) => visit({ group })}
                        options={groupings}
                        className="shrink-0 self-start whitespace-nowrap"
                    />
                )}
            </div>

            {report.views.length > 0 && (
                <PageTabs
                    label={`${report.label} views`}
                    tabs={report.views.map((v) => ({ label: v.label, value: v.value }))}
                    value={filters.view}
                    onChange={(view) => visit({ view })}
                />
            )}

            <div className={cn('flex flex-col gap-6 transition-opacity', loading && 'pointer-events-none opacity-60')} aria-busy={loading}>
                {!result.available ? (
                    <SectionCard>
                        <EmptyState icon={Boxes} title="Comes with stock control (module 5.1)" body={result.notes.join(' ')} />
                    </SectionCard>
                ) : (
                    <>
                        {result.notes.length > 0 && (
                            <Alert variant="info">
                                <Info className="size-4" aria-hidden />
                                <AlertDescription>
                                    {result.notes.map((note) => (
                                        <p key={note}>{note}</p>
                                    ))}
                                </AlertDescription>
                            </Alert>
                        )}

                        <ReportSummary figures={result.summary} versus={versus ? `vs ${versus}` : ''} />

                        {result.chart?.type === 'series' && result.chart.points.length > 1 && (
                            <SeriesChartCard
                                chart={result.chart}
                                compareLabel={versus ? (compares.find((c) => c.value === filters.compare)?.label ?? null) : null}
                            />
                        )}
                        {result.chart?.type === 'heatmap' && <HeatmapCard chart={result.chart} />}

                        {result.tables.map((table) => (
                            <ReportTableCard key={table.key} table={table} onPage={(page) => visit({ page }, true)} />
                        ))}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
