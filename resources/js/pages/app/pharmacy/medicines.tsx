import { CLASSES, ClassPill, PharmacyPageLayout, shopDateTime } from '@/components/app/pharmacy/format';
import { MedicineDialog, type MedicineTarget } from '@/components/app/pharmacy/medicine-dialog';
import { type MedicineClass, type MedicineRow, type MedicinesProps } from '@/components/app/pharmacy/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { number } from '@/components/shared/trading/format';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Info, Pencil, Pill, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

const ONLY = ['rows', 'counts', 'class'];
const ICON_TONES = { generalSale: 'neutral', pharmacyOnly: 'warning', prescriptionOnly: 'danger' } as const;

/** Module 5.10: which products are General Sale, Pharmacy-only or Prescription-only (hub-owned: every till gets them). */
export default function PharmacyMedicines({ rows, counts, class: current, candidates, canEdit }: MedicinesProps) {
    const { update, loading } = useTableQuery({ only: ONLY });
    const [dialog, setDialog] = useState<{ open: boolean; target: MedicineTarget | null }>({ open: false, target: null });

    const columns = useMemo<ColumnDef<MedicineRow>[]>(
        () => [
            {
                id: 'product_name',
                header: 'Product',
                enableSorting: true,
                meta: { mobile: 'title' },
                cell: ({ row }) => (
                    <div className="grid leading-5">
                        <span className="font-medium">{row.original.product}</span>
                        {row.original.sku && <span className="text-muted-foreground text-xs">{row.original.sku}</span>}
                    </div>
                ),
            },
            { id: 'status', header: 'Class', meta: { mobile: 'aside' }, cell: ({ row }) => <ClassPill value={row.original.class} /> },
            {
                id: 'note',
                header: 'Note for the till',
                meta: { mobile: 'field' },
                cell: ({ row }) => <span className="text-muted-foreground line-clamp-2 max-w-sm text-sm">{row.original.note ?? '—'}</span>,
            },
            {
                id: 'updated',
                header: 'Changed',
                meta: { mobile: 'hidden' },
                cell: ({ row }) => <span className="text-muted-foreground text-sm">{shopDateTime(row.original.updatedAt)}</span>,
            },
            ...(canEdit
                ? [
                      {
                          id: 'actions',
                          header: '',
                          meta: { align: 'right' as const, mobile: 'actions' as const },
                          cell: ({ row }: { row: { original: MedicineRow } }) => (
                              <div className="flex justify-end gap-1">
                                  <Button
                                      variant="ghost"
                                      size="icon"
                                      aria-label={`Change ${row.original.product}'s class`}
                                      onClick={() =>
                                          setDialog({
                                              open: true,
                                              target: {
                                                  productId: row.original.productId,
                                                  product: row.original.product,
                                                  class: row.original.class,
                                                  note: row.original.note,
                                              },
                                          })
                                      }
                                  >
                                      <Pencil />
                                  </Button>
                                  <ConfirmDialog
                                      trigger={
                                          <Button variant="ghost" size="icon" aria-label={`Remove ${row.original.product}'s class`}>
                                              <Trash2 />
                                          </Button>
                                      }
                                      title={`Remove ${row.original.product}'s medicine class?`}
                                      description="The tills stop treating it as a medicine at their next sync."
                                      confirmLabel="Remove class"
                                      destructive
                                      onConfirm={() =>
                                          new Promise<void>((resolve) =>
                                              router.delete(route('app.pharmacy.medicines.remove', row.original.productId), {
                                                  preserveScroll: true,
                                                  onFinish: () => resolve(),
                                              }),
                                          )
                                      }
                                  />
                              </div>
                          ),
                      } satisfies ColumnDef<MedicineRow>,
                  ]
                : []),
        ],
        [canEdit],
    );

    return (
        <PharmacyPageLayout
            tab="medicines"
            title="Medicine classes"
            description="Which products are General Sale (GSL), Pharmacy only (P) or Prescription only (POM). Set here for every shop."
        >
            {!canEdit && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        Medicine classes apply to every shop. Ask an owner, or a manager of every shop, to change them.
                    </AlertDescription>
                </Alert>
            )}
            <StatGrid columns={3}>
                {counts.map((c) => (
                    <StatCard
                        key={c.class}
                        label={`${CLASSES[c.class].label} (${CLASSES[c.class].short})`}
                        value={number(c.count)}
                        hint={CLASSES[c.class].help}
                        icon={Pill}
                        tone={ICON_TONES[c.class]}
                        href={route('app.pharmacy.medicines', { class: c.class })}
                    />
                ))}
            </StatGrid>
            <DataTable
                columns={columns}
                data={rows.data}
                meta={rows.meta}
                onChange={update}
                loading={loading}
                searchPlaceholder="Search by product or code"
                filters={
                    <FilterSelect
                        value={current}
                        onChange={(value) => update({ class: value as MedicineClass | undefined, page: undefined })}
                        all="Every class"
                        options={(Object.keys(CLASSES) as MedicineClass[]).map((c) => ({ value: c, label: CLASSES[c].label }))}
                        label="Filter by class"
                    />
                }
                toolbarActions={
                    canEdit && (
                        <Button onClick={() => setDialog({ open: true, target: null })}>
                            <Plus />
                            Classify a product
                        </Button>
                    )
                }
                getRowId={(row) => row.id}
                empty={
                    <EmptyState
                        icon={Pill}
                        title="No medicine classes yet"
                        body="Classify the medicines you sell so every till knows which need a pharmacist or a prescription."
                        action={
                            canEdit ? (
                                <Button onClick={() => setDialog({ open: true, target: null })}>
                                    <Plus />
                                    Classify a product
                                </Button>
                            ) : undefined
                        }
                    />
                }
            />
            {canEdit && (
                <MedicineDialog
                    open={dialog.open}
                    onOpenChange={(open) => setDialog((d) => ({ ...d, open }))}
                    target={dialog.target}
                    candidates={candidates}
                />
            )}
        </PharmacyPageLayout>
    );
}
