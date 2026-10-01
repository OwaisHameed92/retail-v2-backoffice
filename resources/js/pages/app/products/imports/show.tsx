import { ImportMapping } from '@/components/app/products/import-mapping';
import { ImportErrors, ImportSample } from '@/components/app/products/import-result';
import { IMPORT_TONES, type ImportDetail, type ImportField } from '@/components/app/products/import-types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { relativeTime } from '@/lib/relative-time';
import { Head, Link, router } from '@inertiajs/react';
import { CircleAlert, CircleCheck, CirclePlus, FileSpreadsheet, Package, RefreshCw, Upload } from 'lucide-react';
import { useEffect } from 'react';

const number = new Intl.NumberFormat('en-GB');

export default function ProductImportShow({ import: detail, fields }: { import: ImportDetail; fields: ImportField[] }) {
    const applying = detail.status === 'queued' || detail.status === 'running';
    const done = detail.status === 'completed' || detail.status === 'failed';

    useEffect(() => {
        if (!applying) return;
        const timer = window.setInterval(() => router.reload({ only: ['import'] }), 2000);
        return () => window.clearInterval(timer);
    }, [applying]);

    const percent = detail.validRows > 0 ? Math.min(100, Math.round((detail.processedRows / Math.max(detail.totalRows, 1)) * 100)) : 0;

    return (
        <AppLayout>
            <Head title={`Import ${detail.fileName}`} />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <PageHeader
                    title={detail.fileName}
                    status={<StatusBadge status={detail.status} label={detail.statusLabel} tone={IMPORT_TONES[detail.status]} />}
                    description={`${number.format(detail.totalRows || 0)} rows · uploaded ${detail.createdAt ? relativeTime(detail.createdAt) : ''}${detail.by ? ` by ${detail.by}` : ''}`}
                    back={{ href: route('app.products.imports.index'), label: 'Imports' }}
                />

                {detail.status === 'uploaded' && <ImportMapping key={detail.previewedAt ?? 'new'} detail={detail} fields={fields} />}

                {detail.status === 'uploaded' && detail.preview && (
                    <>
                        <StatGrid>
                            <StatCard label="Rows" value={number.format(detail.totalRows)} hint="In the file" icon={FileSpreadsheet} tone="neutral" />
                            <StatCard
                                label="New products"
                                value={number.format(detail.preview.new)}
                                hint="Will be added"
                                icon={CirclePlus}
                                tone="success"
                            />
                            <StatCard
                                label="Updates"
                                value={number.format(detail.preview.update)}
                                hint="Found by barcode or code"
                                icon={RefreshCw}
                                tone="primary"
                            />
                            <StatCard
                                label="Rows with problems"
                                value={number.format(detail.errorRows)}
                                hint={detail.errorRows > 0 ? 'Left out of the import' : 'None'}
                                icon={CircleAlert}
                                tone={detail.errorRows > 0 ? 'warning' : 'success'}
                            />
                        </StatGrid>

                        <div className="flex flex-wrap items-center justify-end gap-3">
                            <p className="text-muted-foreground mr-auto text-sm">
                                New departments and categories named in the file are created. Every till gets the changes at its next sync.
                            </p>
                            <ConfirmDialog
                                trigger={
                                    <Button disabled={detail.validRows === 0}>
                                        <Upload />
                                        Import {number.format(detail.validRows)} {detail.validRows === 1 ? 'row' : 'rows'}
                                    </Button>
                                }
                                title={`Import ${number.format(detail.validRows)} rows?`}
                                description={`${number.format(detail.preview.new)} new products are added and ${number.format(detail.preview.update)} are updated for every shop. ${detail.errorRows > 0 ? `${number.format(detail.errorRows)} rows with problems are left out.` : ''}`}
                                confirmLabel="Start import"
                                onConfirm={() =>
                                    new Promise((resolve) =>
                                        router.post(route('app.products.imports.apply', detail.id), {}, { preserveScroll: true, onFinish: resolve }),
                                    )
                                }
                            />
                        </div>

                        <ImportSample detail={detail} />
                        <ImportErrors detail={detail} title="Rows with problems" />
                    </>
                )}

                {applying && (
                    <SectionCard title="Importing" description="This carries on if you leave the page.">
                        <div className="grid gap-2">
                            <div
                                className="bg-muted h-2 overflow-hidden rounded-full"
                                role="progressbar"
                                aria-valuenow={percent}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-label="Import progress"
                            >
                                <div className="bg-primary h-full rounded-full transition-all" style={{ width: `${percent}%` }} />
                            </div>
                            <p className="text-muted-foreground text-sm tabular-nums">
                                {number.format(detail.processedRows)} of {number.format(detail.totalRows)} rows · {percent}%
                            </p>
                        </div>
                    </SectionCard>
                )}

                {done && (
                    <>
                        {detail.status === 'failed' ? (
                            <Alert variant="destructive">
                                <CircleAlert />
                                <AlertTitle>The import stopped</AlertTitle>
                                <AlertDescription>
                                    {number.format(detail.processedRows)} rows were handled before it stopped. Upload the file again to finish: rows
                                    already imported are not added twice.
                                </AlertDescription>
                            </Alert>
                        ) : (
                            <Alert variant="success">
                                <CircleCheck />
                                <AlertTitle>Import finished {detail.finishedAt ? relativeTime(detail.finishedAt) : ''}</AlertTitle>
                                <AlertDescription>Your tills get the changes at their next sync.</AlertDescription>
                            </Alert>
                        )}
                        <StatGrid>
                            <StatCard label="Added" value={number.format(detail.created)} hint="New products" icon={CirclePlus} tone="success" />
                            <StatCard label="Updated" value={number.format(detail.updated)} hint="Changed products" icon={RefreshCw} tone="primary" />
                            <StatCard
                                label="Unchanged"
                                value={number.format(detail.unchanged)}
                                hint="Already up to date"
                                icon={Package}
                                tone="neutral"
                            />
                            <StatCard
                                label="Not imported"
                                value={number.format(detail.failed)}
                                hint="See the list below"
                                icon={CircleAlert}
                                tone={detail.failed > 0 ? 'warning' : 'success'}
                            />
                        </StatGrid>
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button variant="outline" asChild>
                                <Link href={route('app.products.imports.index')}>Import another file</Link>
                            </Button>
                            <Button asChild>
                                <Link href={route('app.products.index')}>View products</Link>
                            </Button>
                        </div>
                        <ImportErrors detail={detail} title="Rows not imported" />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
