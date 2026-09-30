import { cellText, isNumeric, reportQuery, reportUrl } from '@/components/app/reports/format';
import { type ReportPrintProps } from '@/components/app/reports/types';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Head } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

const PAGE_CSS = '@page { size: A4 landscape; margin: 12mm; } @media print { html, body { background: white !important; } }';

/**
 * A report laid out for paper (module 4.8): heading (business, shop, till, dates, compare, created), the headline
 * figures and every table in full. The toolbar is hidden when printing; tables do not split a row across pages.
 */
export default function ReportPrint({ report, filters, heading, result }: ReportPrintProps) {
    const back = reportUrl('app.reports.show', report.value, reportQuery({ ...filters, page: 1 }));

    return (
        <div className="bg-background text-foreground min-h-screen print:bg-white print:text-black">
            <Head title={`${report.label} (print)`} />
            <style>{PAGE_CSS}</style>

            <div className="bg-card sticky top-0 z-10 flex items-center justify-between gap-3 border-b px-4 py-3 print:hidden">
                <Button variant="ghost" asChild>
                    <a href={back}>
                        <ArrowLeft className="size-4" aria-hidden />
                        Back to the report
                    </a>
                </Button>
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" aria-hidden />
                    Print
                </Button>
            </div>

            <main className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 text-sm print:max-w-none print:gap-4 print:p-0 print:text-[11px]">
                <header className="flex items-start justify-between gap-6 border-b pb-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight print:text-xl">{report.label}</h1>
                        <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5">
                            {Object.entries(heading)
                                .filter(([label]) => label !== 'Report')
                                .map(([label, value]) => (
                                    <div key={label} className="contents">
                                        <dt className="text-muted-foreground print:text-neutral-600">{label}</dt>
                                        <dd className="font-medium">{value}</dd>
                                    </div>
                                ))}
                        </dl>
                    </div>
                    <AppLogoIcon className="size-10 shrink-0" />
                </header>

                {result.notes.map((note) => (
                    <p key={note} className="text-muted-foreground print:text-neutral-600">
                        {note}
                    </p>
                ))}

                {result.summary.length > 0 && (
                    <section className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 print:grid-cols-5">
                        {result.summary.map((f) => (
                            <div key={f.key} className="rounded-md border p-3 break-inside-avoid">
                                <p className="text-muted-foreground text-xs print:text-neutral-600">{f.label}</p>
                                <p className="mt-1 text-lg font-semibold tabular-nums print:text-base">{cellText(f.value, f.type)}</p>
                                {f.previous !== null && (
                                    <p className="text-muted-foreground text-xs tabular-nums print:text-neutral-600">
                                        Was {cellText(f.previous, f.type)}
                                        {f.change !== null && ` (${Number(f.change) > 0 ? '+' : ''}${f.change}%)`}
                                    </p>
                                )}
                            </div>
                        ))}
                    </section>
                )}

                {result.tables.map((table) => (
                    <section key={table.key} className="flex flex-col gap-2">
                        <h2 className="text-base font-semibold break-after-avoid">{table.title}</h2>
                        {table.description && <p className="text-muted-foreground text-xs print:text-neutral-600">{table.description}</p>}
                        {table.rows.length === 0 ? (
                            <p className="text-muted-foreground print:text-neutral-600">{table.empty}</p>
                        ) : (
                            <div className="overflow-x-auto print:overflow-visible">
                                <table className="w-full border-collapse">
                                    <thead className="table-header-group">
                                        <tr className="border-b-2">
                                            {table.columns.map((c) => (
                                                <th key={c.key} className={cn('px-2 py-1.5 text-left font-semibold whitespace-nowrap', isNumeric(c.type) && 'text-right')}>
                                                    {c.label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {table.rows.map((row, i) => (
                                            <tr key={String(row.id ?? i)} className="border-b break-inside-avoid">
                                                {table.columns.map((c) => (
                                                    <td key={c.key} className={cn('px-2 py-1 whitespace-nowrap', isNumeric(c.type) && 'text-right tabular-nums')}>
                                                        {c.type === 'flag' && row[c.key] !== true ? '' : cellText(row[c.key] ?? null, c.type)}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                    {table.totals && (
                                        <tfoot className="table-row-group">
                                            <tr className="border-t-2 font-semibold">
                                                {table.columns.map((c) => (
                                                    <td key={c.key} className={cn('px-2 py-1.5 whitespace-nowrap', isNumeric(c.type) && 'text-right tabular-nums')}>
                                                        {c.key in (table.totals ?? {}) ? cellText(table.totals?.[c.key] ?? null, c.type) : ''}
                                                    </td>
                                                ))}
                                            </tr>
                                        </tfoot>
                                    )}
                                </table>
                            </div>
                        )}
                    </section>
                ))}

                {!result.available && <p className="text-muted-foreground">Nothing to print yet.</p>}
            </main>
        </div>
    );
}
