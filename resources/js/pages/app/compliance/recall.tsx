import { dash, formatDateTime, formatDay } from '@/components/app/compliance/format';
import { RecallDialog } from '@/components/app/compliance/recall-dialog';
import { RecallStatus } from '@/components/app/compliance/recall-status';
import { type RecallProps } from '@/components/app/compliance/types';
import { DescriptionList } from '@/components/shared/description-list';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { number } from '@/components/shared/trading/format';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Boxes, Pencil } from 'lucide-react';
import { useState } from 'react';

const qty = (v: string | null) => (v === null ? dash : <span className="tabular-nums">{number(Number(v))}</span>);

/**
 * One product recall (module 5.7): what, why, the stock it touches and what each shop sent back. The portal edits its
 * text (compliance.manage); closing, reopening and returns are done at a till, so the status is read only here.
 */
export default function ComplianceRecall({ recall, stock, matchesBatches, suppliers, productResults, canManage }: RecallProps) {
    const [editing, setEditing] = useState(false);
    const anyReturned = stock.some((s) => s.returned !== null);

    return (
        <AppLayout>
            <Head title={`${recall.reference ?? 'Recall'} · Recalls`} />
            <PageHeader
                title={recall.product ?? 'Recall'}
                back={{ href: route('app.compliance.recalls'), label: 'Recalls' }}
                status={<RecallStatus status={recall.status} />}
                description={[
                    recall.reference,
                    `Raised ${formatDateTime(recall.raisedAt)}`,
                    recall.fromPortal ? 'Sent to every till' : 'Changed by a till',
                ]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    canManage && (
                        <Button variant="outline" onClick={() => setEditing(true)}>
                            <Pencil />
                            Edit
                        </Button>
                    )
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
                <div className="grid gap-4 lg:col-span-2">
                    <SectionCard title="Why it is recalled">
                        <p className="text-sm leading-6 whitespace-pre-line">{recall.reason ?? 'No reason given.'}</p>
                        {recall.note && (
                            <div className="bg-muted mt-4 rounded-md px-4 py-3 text-sm">
                                <p className="font-medium">Closing note from a till</p>
                                <p className="text-muted-foreground mt-1 whitespace-pre-line">{recall.note}</p>
                            </div>
                        )}
                    </SectionCard>
                    <SectionCard
                        title="Stock in the shops"
                        description={
                            matchesBatches
                                ? 'On hand of the product, what is left of deliveries with a matching batch or best-before date, and what each shop sent back.'
                                : 'On hand of the product in each shop, and what each shop sent back.'
                        }
                        flush
                    >
                        {stock.length === 0 ? (
                            <EmptyState
                                icon={Boxes}
                                title={recall.productId ? 'No stock on record' : 'Not linked to a catalogue product'}
                                body={
                                    recall.productId
                                        ? 'None of the shops has this product on hand.'
                                        : 'Link the recall to a product to see the stock it touches.'
                                }
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-5">Shop</TableHead>
                                        <TableHead className={matchesBatches || anyReturned ? 'text-right' : 'pr-5 text-right'}>On hand</TableHead>
                                        {matchesBatches && <TableHead className={anyReturned ? 'text-right' : 'pr-5 text-right'}>In matching batches</TableHead>}
                                        {anyReturned && <TableHead className="pr-5 text-right">Returned</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {stock.map((s) => (
                                        <TableRow key={s.shop}>
                                            <TableCell className="pl-5 font-medium">{s.shop}</TableCell>
                                            <TableCell className={matchesBatches || anyReturned ? 'text-right' : 'pr-5 text-right'}>{qty(s.onHand)}</TableCell>
                                            {matchesBatches && (
                                                <TableCell className={anyReturned ? 'text-right' : 'pr-5 text-right'}>
                                                    {qty(s.batchQty)}
                                                    {s.batches > 0 && (
                                                        <span className="text-muted-foreground ml-1 text-xs">
                                                            ({s.batches} deliver{s.batches === 1 ? 'y' : 'ies'})
                                                        </span>
                                                    )}
                                                </TableCell>
                                            )}
                                            {anyReturned && <TableCell className="pr-5 text-right">{qty(s.returned)}</TableCell>}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </SectionCard>
                </div>
                <SectionCard title="Details" description="Closed, reopened and returns are done at a till.">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Reference', value: recall.reference, mono: true },
                            { label: 'Batch or lot', value: recall.batchCode ?? 'Every batch' },
                            { label: 'Best before from', value: recall.expiryFrom ? formatDay(recall.expiryFrom) : null },
                            { label: 'Best before to', value: recall.expiryTo ? formatDay(recall.expiryTo) : null },
                            { label: 'Notice from', value: recall.source },
                            { label: 'Supplier', value: recall.supplier },
                            { label: 'Returned to supplier', value: Number(recall.returnedQty) > 0 ? number(Number(recall.returnedQty)) : null },
                            { label: 'Closed', value: recall.closedAt ? formatDateTime(recall.closedAt) : null },
                        ]}
                    />
                </SectionCard>
            </div>

            {editing && <RecallDialog recall={recall} suppliers={suppliers} productResults={productResults} onClose={() => setEditing(false)} />}
        </AppLayout>
    );
}
