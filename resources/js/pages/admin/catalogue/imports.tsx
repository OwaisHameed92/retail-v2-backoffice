import { CatalogueTabs } from '@/components/admin/catalogue/catalogue-tabs';
import { type ImportProps, type ImportRow } from '@/components/admin/catalogue/types';
import { formatDateTime } from '@/components/admin/format';
import { IMPORT_TONES } from '@/components/app/products/import-types';
import { EmptyState } from '@/components/shared/empty-state';
import { FormField } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { Download, FileSpreadsheet, LoaderCircle, Upload } from 'lucide-react';
import { useEffect, useState, type FormEventHandler } from 'react';

const number = new Intl.NumberFormat('en-GB');

function ImportResult({ row }: { row: ImportRow }) {
    const [open, setOpen] = useState(false);
    const busy = row.status === 'queued' || row.status === 'running';

    return (
        <li className="grid gap-2 px-5 py-3">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                <FileSpreadsheet className="text-muted-foreground size-4" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{row.fileName}</p>
                    <p className="text-muted-foreground truncate text-xs">
                        {[row.sourceRef !== row.fileName ? row.sourceRef : null, row.by, formatDateTime(row.createdAt)].filter(Boolean).join(' · ')}
                    </p>
                </div>
                <span className="text-muted-foreground text-sm tabular-nums">
                    {busy
                        ? `${number.format(row.processed)} rows read…`
                        : `${number.format(row.created)} added · ${number.format(row.updated)} updated · ${number.format(row.unchanged)} unchanged · ${number.format(row.failed)} not loaded`}
                </span>
                <StatusBadge status={row.status} label={row.statusLabel} tone={IMPORT_TONES[row.status]} />
            </div>
            {row.errors.length > 0 && (
                <div className="pl-8">
                    <button
                        type="button"
                        className="text-primary text-sm underline-offset-4 hover:underline"
                        onClick={() => setOpen(!open)}
                        aria-expanded={open}
                    >
                        {open ? 'Hide rows not loaded' : `Show rows not loaded (${number.format(row.failed)})`}
                    </button>
                    {open && (
                        <ul className="text-muted-foreground mt-2 grid max-h-64 gap-1 overflow-auto text-sm">
                            {row.errors.map((error) => (
                                <li key={error.row}>
                                    <span className="text-foreground font-medium tabular-nums">Line {error.row}:</span> {error.messages.join(' ')}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </li>
    );
}

/** Load a CSV into the master catalogue (a licensed supplier file), applied in chunks in the background. */
export default function MasterImports({ imports, columns }: ImportProps) {
    const { data, setData, post, processing, errors, progress, reset } = useForm<{ file: File | null; source_ref: string }>({
        file: null,
        source_ref: '',
    });
    const running = imports.some((row) => row.status === 'queued' || row.status === 'running');

    useEffect(() => {
        if (!running) {
            return;
        }
        const timer = window.setInterval(() => router.reload({ only: ['imports'] }), 3000);

        return () => window.clearInterval(timer);
    }, [running]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.catalogue.imports.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <AdminLayout>
            <Head title="Catalogue CSV loads" />
            <PageHeader
                title="Catalogue"
                description="Load a supplier or licensed product file. Rows are matched by barcode: new barcodes are added, known ones updated from the cells that are not empty."
                actions={
                    <Button variant="outline" asChild>
                        <a href={route('admin.catalogue.imports.template')}>
                            <Download />
                            Template
                        </a>
                    </Button>
                }
                tabs={<CatalogueTabs />}
            />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="grid content-start gap-6">
                    <SectionCard
                        title="Load a CSV file"
                        description="UTF-8 CSV, comma, semicolon or tab separated, up to 200 MB. Large files load in the background."
                    >
                        <form onSubmit={submit} className="grid gap-4">
                            <label
                                htmlFor="file"
                                className={cn(
                                    'hover:bg-subtle flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed px-6 py-8 text-center transition-colors',
                                    errors.file && 'border-danger',
                                )}
                            >
                                <FileSpreadsheet className="text-muted-foreground size-8" aria-hidden />
                                <span className="text-sm font-medium">{data.file ? data.file.name : 'Choose a CSV file'}</span>
                                <span className="text-muted-foreground text-sm">
                                    {data.file ? `${number.format(Math.ceil(data.file.size / 1024))} KB` : 'The first line must name the columns.'}
                                </span>
                                <input
                                    id="file"
                                    type="file"
                                    accept=".csv,text/csv,text/plain"
                                    className="sr-only"
                                    onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                                />
                            </label>
                            {errors.file && <p className="text-danger-foreground text-[13px]">{errors.file}</p>}
                            <FormField
                                id="source_ref"
                                label="Source"
                                optional
                                help="Who supplied the file and under what licence, e.g. “Supplier X licensed file, Nov 2026”."
                                error={errors.source_ref}
                            >
                                <Input
                                    id="source_ref"
                                    maxLength={255}
                                    value={data.source_ref}
                                    onChange={(e) => setData('source_ref', e.target.value)}
                                />
                            </FormField>
                            <div className="flex flex-wrap items-center justify-end gap-3">
                                {progress && <span className="text-muted-foreground text-sm tabular-nums">Uploading {progress.percentage}%</span>}
                                <Button type="submit" disabled={!data.file || processing || running}>
                                    {processing ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <Upload />}
                                    Load file
                                </Button>
                            </div>
                        </form>
                    </SectionCard>

                    <SectionCard title="Recent loads" flush>
                        {imports.length === 0 ? (
                            <EmptyState
                                size="sm"
                                icon={FileSpreadsheet}
                                title="No loads yet"
                                body="Files you load and their results are listed here."
                            />
                        ) : (
                            <ul className="divide-y">
                                {imports.map((row) => (
                                    <ImportResult key={row.id} row={row} />
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </div>

                <SectionCard
                    title="Columns read"
                    description="Any order. Each column is recognised by any of these names; others are ignored. A barcode and a name are required."
                >
                    <dl className="grid gap-3 text-sm">
                        {columns.map((column) => (
                            <div key={column.field}>
                                <dt className="font-medium">{column.field.replaceAll('_', ' ')}</dt>
                                <dd className="text-muted-foreground font-mono text-xs">{column.names.join(', ')}</dd>
                            </div>
                        ))}
                    </dl>
                </SectionCard>
            </div>
        </AdminLayout>
    );
}
