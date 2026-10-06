import { IMPORT_TONES, type ImportField, type ImportRow } from '@/components/app/products/import-types';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/country';
import { relativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { Download, FileSpreadsheet, LoaderCircle, Upload } from 'lucide-react';
import { useRef, useState, type FormEventHandler } from 'react';

export default function ProductImports({ imports, fields }: { imports: ImportRow[]; fields: ImportField[] }) {
    const { setData, post, processing, errors, data, progress } = useForm<{ file: File | null }>({ file: null });
    const [dragging, setDragging] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('app.products.imports.store'), { forceFormData: true });
    };

    return (
        <AppLayout>
            <Head title="Import products" />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <PageHeader
                    title="Import products"
                    description="Add or update products from a spreadsheet. Products are found by barcode, then by product code, so importing the same file twice changes nothing."
                    back={{ href: route('app.products.index'), label: 'Products' }}
                    actions={
                        <Button variant="outline" asChild>
                            <a href={route('app.products.imports.template')}>
                                <Download />
                                Template
                            </a>
                        </Button>
                    }
                />

                <SectionCard title="Upload a CSV file" description="Save your spreadsheet as CSV (in Excel: File, Save as, CSV UTF-8). Up to 20 MB.">
                    <form onSubmit={submit} className="grid gap-4">
                        <label
                            htmlFor="file"
                            onDragOver={(e) => {
                                e.preventDefault();
                                setDragging(true);
                            }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={(e) => {
                                e.preventDefault();
                                setDragging(false);
                                setData('file', e.dataTransfer.files[0] ?? null);
                            }}
                            className={cn(
                                'hover:bg-subtle flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed px-6 py-10 text-center transition-colors',
                                dragging && 'border-primary bg-subtle',
                                errors.file && 'border-danger',
                            )}
                        >
                            <FileSpreadsheet className="text-muted-foreground size-8" aria-hidden />
                            <span className="text-sm font-medium">{data.file ? data.file.name : 'Choose a file or drop it here'}</span>
                            <span className="text-muted-foreground text-sm">
                                {data.file ? `${formatNumber(Math.ceil(data.file.size / 1024))} KB` : 'The first line must name the columns.'}
                            </span>
                            <input
                                ref={input}
                                id="file"
                                type="file"
                                accept=".csv,text/csv,text/plain"
                                className="sr-only"
                                onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                            />
                        </label>
                        {errors.file && <p className="text-danger-foreground text-[13px]">{errors.file}</p>}
                        <div className="flex flex-wrap items-center justify-end gap-3">
                            {progress && <span className="text-muted-foreground text-sm tabular-nums">Uploading {progress.percentage}%</span>}
                            <Button type="submit" disabled={!data.file || processing}>
                                {processing ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <Upload />}
                                Upload and match columns
                            </Button>
                        </div>
                    </form>
                </SectionCard>

                <SectionCard
                    title="Columns you can import"
                    description="Any order, any column names: you match them on the next step. Empty cells keep what the product already has."
                >
                    <dl className="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
                        {fields.map((field) => (
                            <div key={field.value}>
                                <dt className="text-sm font-medium">{field.label}</dt>
                                {field.help && <dd className="text-muted-foreground text-sm">{field.help}</dd>}
                            </div>
                        ))}
                    </dl>
                </SectionCard>

                <SectionCard title="Recent imports" flush>
                    {imports.length === 0 ? (
                        <EmptyState size="sm" icon={FileSpreadsheet} title="No imports yet" body="Your uploads and their results are listed here." />
                    ) : (
                        <ul className="divide-y">
                            {imports.map((row) => (
                                <li key={row.id}>
                                    <Link
                                        href={route('app.products.imports.show', row.id)}
                                        className="hover:bg-subtle flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3"
                                    >
                                        <FileSpreadsheet className="text-muted-foreground size-4" aria-hidden />
                                        <span className="min-w-0 flex-1 truncate text-sm font-medium">{row.fileName}</span>
                                        <span className="text-muted-foreground text-sm tabular-nums">
                                            {row.status === 'completed'
                                                ? `${formatNumber(row.created)} added · ${formatNumber(row.updated)} updated · ${formatNumber(row.failed)} not imported`
                                                : `${formatNumber(row.totalRows)} rows`}
                                        </span>
                                        <span className="text-muted-foreground text-sm">{row.createdAt ? relativeTime(row.createdAt) : ''}</span>
                                        <StatusBadge status={row.status} label={row.statusLabel} tone={IMPORT_TONES[row.status]} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </AppLayout>
    );
}
