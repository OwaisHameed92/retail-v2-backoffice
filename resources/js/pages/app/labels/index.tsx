import { AddLabelsDialog } from '@/components/app/labels/add-labels-dialog';
import { PrintDialog } from '@/components/app/labels/print-dialog';
import { TemplatesCard } from '@/components/app/labels/templates-card';
import { type LabelsPageProps, type QueueRow } from '@/components/app/labels/types';
import { formatDateTime, pounds } from '@/components/app/pricing/format';
import { FilterSelect } from '@/components/app/setup/fields';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { showToast } from '@/components/shared/toaster';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Barcode, CalendarClock, CheckCheck, Package, Plus, Printer, Store, Tag, Tags, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const ONLY = ['items', 'filters', 'counts'];
const number = new Intl.NumberFormat('en-GB');

const REASON_TONES: Record<string, 'info' | 'violet' | 'warning' | 'neutral' | 'success'> = {
    priceChange: 'info',
    shopPrice: 'info',
    shopPriceEnded: 'neutral',
    promotionStarted: 'violet',
    promotionEnded: 'warning',
    manual: 'neutral',
};

export default function LabelsIndex({ shops, shop, restrictedShop, filters, reasons, stocks, items, counts, templates, departments, suppliers }: LabelsPageProps) {
    const { update } = useTableQuery({ only: ONLY });
    const [selected, setSelected] = useState<string[]>([]);
    const [printing, setPrinting] = useState<string[] | 'all' | null>(null);
    const [adding, setAdding] = useState(false);
    const waitingView = filters.view === 'waiting';
    const filtered = Boolean(items.meta.search) || filters.reason !== null;

    useEffect(() => setSelected([]), [shop?.id, filters.view, items.meta.page]);

    const send = (path: 'app.labels.printed' | 'app.labels.remove' | 'app.labels.copies', data: Record<string, unknown>) =>
        new Promise<void>((resolve) =>
            router.post(route(path), { branch_id: shop?.id, ...data } as never, {
                preserveScroll: true,
                only: ['items', 'counts', 'flash', 'errors'],
                onError: (errors) => showToast(Object.values(errors)[0] ?? 'That did not work. Refresh and try again.', 'error'),
                onSuccess: () => setSelected([]),
                onFinish: () => resolve(),
            }),
        );

    const columns = useMemo<ColumnDef<QueueRow>[]>(() => {
        const all = items.data.length > 0 && items.data.every((row) => selected.includes(row.id));
        return [
            {
                id: 'select',
                header: () => (
                    <Checkbox
                        checked={all ? true : selected.length > 0 ? 'indeterminate' : false}
                        onCheckedChange={(checked) => setSelected(checked === true ? items.data.map((r) => r.id) : [])}
                        aria-label="Select all on this page"
                    />
                ),
                cell: ({ row }) => (
                    <Checkbox
                        checked={selected.includes(row.original.id)}
                        onCheckedChange={(checked) =>
                            setSelected((current) => (checked === true ? [...current, row.original.id] : current.filter((id) => id !== row.original.id)))
                        }
                        onClick={(e) => e.stopPropagation()}
                        aria-label={`Select ${row.original.name}`}
                    />
                ),
                meta: { mobile: 'hidden' },
            },
            {
                id: 'name',
                header: 'Product',
                cell: ({ row }) => (
                    <EntityCell name={row.original.name} subline={row.original.sku ?? undefined} monoSubline shape="square" icon={Package} className="max-w-72" />
                ),
                meta: { mobile: 'title' },
            },
            {
                id: 'price',
                header: 'Label price',
                cell: ({ row }) => (
                    <div className="grid leading-5">
                        <span className="font-medium tabular-nums">{pounds(row.original.price)}</span>
                        {row.original.ownPrice && <span className="text-muted-foreground text-xs">Shop price</span>}
                    </div>
                ),
                meta: { align: 'right', mobile: 'aside' },
            },
            {
                id: 'reason',
                header: 'Why',
                cell: ({ row }) => (
                    <div className="grid max-w-80 gap-1">
                        <Badge variant={REASON_TONES[row.original.reason] ?? 'neutral'} className="w-fit">
                            {row.original.reasonLabel}
                            {row.original.timesQueued > 1 ? ` ×${row.original.timesQueued}` : ''}
                        </Badge>
                        {row.original.detail && <span className="text-muted-foreground truncate text-xs">{row.original.detail}</span>}
                    </div>
                ),
                meta: { mobile: 'field', label: 'Why' },
            },
            {
                id: waitingView ? 'queued_at' : 'printed_at',
                header: waitingView ? 'Queued' : 'Printed',
                enableSorting: true,
                cell: ({ row }) => (
                    <div className="grid leading-5">
                        <span className="text-sm">{formatDateTime(waitingView ? row.original.queuedAt : row.original.printedAt)}</span>
                        {waitingView && row.original.dueAt && (
                            <span className="text-info-foreground flex items-center gap-1 text-xs">
                                <CalendarClock className="size-3" aria-hidden />
                                Price from {formatDateTime(row.original.dueAt)}
                            </span>
                        )}
                    </div>
                ),
                meta: { mobile: 'field', label: waitingView ? 'Queued' : 'Printed' },
            },
            ...(waitingView
                ? [
                      {
                          id: 'copies',
                          header: 'Copies',
                          cell: ({ row }) => <CopiesInput row={row.original} onSave={(copies) => send('app.labels.copies', { ids: [row.original.id], copies })} />,
                          meta: { align: 'right', mobile: 'field', label: 'Copies' },
                      } satisfies ColumnDef<QueueRow>,
                  ]
                : []),
            {
                id: 'actions',
                header: () => <span className="sr-only">Actions</span>,
                cell: ({ row }) => (
                    <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
                        <Button size="icon" variant="ghost" className="size-8" aria-label={`Print ${row.original.name}`} onClick={() => setPrinting([row.original.id])}>
                            <Printer className="size-4" />
                        </Button>
                        {waitingView && (
                            <ConfirmDialog
                                title="Take this label off the queue?"
                                description={`${row.original.name} will not be printed. It comes back when its price or offer changes again.`}
                                confirmLabel="Take off"
                                onConfirm={() => send('app.labels.remove', { ids: [row.original.id] })}
                                trigger={
                                    <Button size="icon" variant="ghost" className="size-8" aria-label={`Take ${row.original.name} off the queue`}>
                                        <X className="size-4" />
                                    </Button>
                                }
                            />
                        )}
                    </div>
                ),
                meta: { align: 'right', mobile: 'actions' },
            },
        ];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items.data, selected, waitingView, shop?.id]);

    if (!shop) {
        return (
            <AppLayout>
                <Head title="Shelf labels" />
                <PageHeader title="Shelf labels" description="Print shelf-edge labels when prices and offers change." />
                <EmptyState icon={Store} title="No open shops" body="Labels are printed per shop. Open a shop first." bordered />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Shelf labels" />

            <PageHeader
                title="Shelf labels"
                description="Labels queue themselves when a price or offer changes. Add more by hand, preview and print them on A4 label sheets or a label printer."
                actions={
                    <>
                        {shops.length > 1 && restrictedShop === null && (
                            <Select value={shop.id} onValueChange={(id) => router.get(route('app.labels.index'), { shop: id }, { preserveState: false })}>
                                <SelectTrigger className="h-9 w-full sm:w-52" aria-label="Shop">
                                    <Store className="size-4" aria-hidden />
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {shops.map((s) => (
                                        <SelectItem key={s.id} value={s.id}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <Button variant="outline" onClick={() => setAdding(true)}>
                            <Plus className="size-4" aria-hidden />
                            Add labels
                        </Button>
                        <Button onClick={() => setPrinting('all')} disabled={counts.waiting === 0}>
                            <Printer className="size-4" aria-hidden />
                            Print all waiting
                        </Button>
                    </>
                }
            />

            <StatGrid columns={4}>
                <StatCard label="Waiting to print" value={number.format(counts.waiting)} hint={shop.name} icon={Tags} tone="primary" />
                <StatCard label="Price changes" value={number.format(counts.priceChanges)} hint="Business and shop prices" icon={Barcode} tone="neutral" />
                <StatCard label="Offers" value={number.format(counts.offers)} hint="Started or ended" icon={Tag} tone="warning" />
                <StatCard label="Printed this week" value={number.format(counts.printedWeek)} icon={CheckCheck} tone="success" />
            </StatGrid>

            <PageTabs
                label="Label views"
                value={filters.view}
                onChange={(view) => update({ view: view === 'waiting' ? undefined : view, page: 1, sort: undefined, direction: undefined })}
                tabs={[
                    { label: 'Waiting', value: 'waiting', count: counts.waiting },
                    { label: 'Printed', value: 'printed' },
                ]}
            />

            <DataTable
                columns={columns}
                data={items.data}
                meta={items.meta}
                only={ONLY}
                searchPlaceholder="Search by name, code or barcode"
                filters={
                    <FilterSelect value={filters.reason} onChange={(reason) => update({ reason, page: 1 })} all="Any reason" options={reasons} label="Filter by reason" />
                }
                toolbarActions={
                    selected.length > 0 ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-muted-foreground text-sm">{selected.length} selected</span>
                            <Button size="sm" onClick={() => setPrinting(selected)}>
                                <Printer className="size-4" aria-hidden />
                                Print
                            </Button>
                            {waitingView && (
                                <>
                                    <Button size="sm" variant="outline" onClick={() => send('app.labels.printed', { ids: selected })}>
                                        <CheckCheck className="size-4" aria-hidden />
                                        Mark printed
                                    </Button>
                                    <ConfirmDialog
                                        title={`Take ${selected.length} ${selected.length === 1 ? 'label' : 'labels'} off the queue?`}
                                        description="They will not be printed. Each comes back when its price or offer changes again."
                                        confirmLabel="Take off"
                                        onConfirm={() => send('app.labels.remove', { ids: selected })}
                                        trigger={
                                            <Button size="sm" variant="ghost">
                                                <X className="size-4" aria-hidden />
                                                Take off
                                            </Button>
                                        }
                                    />
                                </>
                            )}
                        </div>
                    ) : undefined
                }
                getRowId={(row) => row.id}
                empty={
                    filtered ? undefined : waitingView ? (
                        <EmptyState
                            icon={Tags}
                            title="Nothing to print"
                            body="When a price or an offer changes, its labels appear here. You can also add labels by hand."
                            action={
                                <Button variant="outline" onClick={() => setAdding(true)}>
                                    <Plus className="size-4" aria-hidden />
                                    Add labels
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState icon={Printer} title="No labels printed yet" body="Labels you print from this shop's queue are listed here." />
                    )
                }
            />

            <TemplatesCard templates={templates} stocks={stocks} shop={shop} restrictedShop={restrictedShop} />

            <AddLabelsDialog open={adding} onOpenChange={setAdding} shopId={shop.id} shopName={shop.name} departments={departments} suppliers={suppliers} />
            {printing !== null && (
                <PrintDialog
                    open
                    onOpenChange={(open) => !open && setPrinting(null)}
                    shopId={shop.id}
                    ids={printing}
                    templates={templates}
                    waiting={printing === 'all' || waitingView}
                    onPrinted={() => setSelected([])}
                />
            )}
        </AppLayout>
    );
}

function CopiesInput({ row, onSave }: { row: QueueRow; onSave: (copies: number) => void }) {
    const [value, setValue] = useState(String(row.copies));
    useEffect(() => setValue(String(row.copies)), [row.copies]);

    const save = () => {
        const copies = Math.round(Number(value));
        if (!Number.isFinite(copies) || copies < 1 || copies > 99) {
            setValue(String(row.copies));
            showToast('Print 1 to 99 copies.', 'error');
            return;
        }
        if (copies !== row.copies) {
            onSave(copies);
        }
    };

    return (
        <Input
            type="number"
            inputMode="numeric"
            min={1}
            max={99}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            onBlur={save}
            onKeyDown={(e) => e.key === 'Enter' && (e.currentTarget as HTMLInputElement).blur()}
            onClick={(e) => e.stopPropagation()}
            className="ml-auto h-8 w-16 text-right tabular-nums"
            aria-label={`Copies of ${row.name}`}
        />
    );
}
