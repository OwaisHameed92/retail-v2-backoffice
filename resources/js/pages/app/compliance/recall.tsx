import { dash, formatDateTime, formatDay } from '@/components/app/compliance/format';
import { RecallDialog } from '@/components/app/compliance/recall-dialog';
import { RecallStatus } from '@/components/app/compliance/recall-status';
import { type RecallProps, type RecallShopState } from '@/components/app/compliance/types';
import { DescriptionList } from '@/components/shared/description-list';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { number } from '@/components/shared/trading/format';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Boxes, Pencil, Store } from 'lucide-react';
import { useState } from 'react';

const qty = (v: string | null) => (v === null ? dash : <span className="tabular-nums">{number(Number(v))}</span>);

/** When and by whom a shop closed the recall, and its note. */
function Closed({ state }: { state: RecallShopState }) {
    if (state.status === 'open') return state.note ? <p className="text-muted-foreground text-xs whitespace-pre-line">{state.note}</p> : dash;

    return (
        <div className="min-w-0">
            <p className="tabular-nums">
                {state.closedAt ? formatDateTime(state.closedAt) : 'Closed'}
                {state.closedBy && <span className="text-muted-foreground"> · {state.closedBy}</span>}
            </p>
            {state.note && <p className="text-muted-foreground mt-0.5 text-xs whitespace-pre-line">{state.note}</p>}
        </div>
    );
}

/**
 * One product recall (module 5.7): what, why, its state in each shop, and the stock it touches there. The portal edits
 * its text (compliance.manage); each shop closes, reopens and returns stock at its own till (till 0.1.52), so the
 * states are read only here.
 */
export default function ComplianceRecall({ recall, shops, stock, matchesBatches, suppliers, productResults, canManage }: RecallProps) {
    const [editing, setEditing] = useState(false);
    const anyReturned = shops.some((s) => s.returned !== null);

    return (
        <AppLayout>
            <Head title={`${recall.reference ?? 'Recall'} · Recalls`} />
            <PageHeader
                title={recall.product ?? 'Recall'}
                back={{ href: route('app.compliance.recalls'), label: 'Recalls' }}
                status={<RecallStatus status={recall.status} openShops={recall.openShops} shops={recall.shops} />}
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
                    </SectionCard>
                    <SectionCard
                        title="Status in each shop"
                        description="Each shop closes the recall at its till once the stock is off the shelves. A shop with nothing recorded is still open."
                        flush
                    >
                        {shops.length === 0 ? (
                            <EmptyState icon={Store} title="No shops" body="Add a shop to see where this recall is open." />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-5">Shop</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className={anyReturned ? undefined : 'pr-5'}>Closed</TableHead>
                                        {anyReturned && <TableHead className="pr-5 text-right">Returned</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {shops.map((s) => (
                                        <TableRow key={s.shop}>
                                            <TableCell className="pl-5 font-medium">{s.shop}</TableCell>
                                            <TableCell>
                                                <RecallStatus status={s.status} />
                                            </TableCell>
                                            <TableCell className={anyReturned ? 'whitespace-normal' : 'pr-5 whitespace-normal'}>
                                                <Closed state={s} />
                                            </TableCell>
                                            {anyReturned && <TableCell className="pr-5 text-right">{qty(s.returned)}</TableCell>}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </SectionCard>
                    <SectionCard
                        title="Stock in the shops"
                        description={
                            matchesBatches
                                ? 'On hand of the product, and what is left of deliveries with a matching batch or best-before date.'
                                : 'On hand of the product in each shop.'
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
                                        <TableHead className={matchesBatches ? 'text-right' : 'pr-5 text-right'}>On hand</TableHead>
                                        {matchesBatches && <TableHead className="pr-5 text-right">In matching batches</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {stock.map((s) => (
                                        <TableRow key={s.shop}>
                                            <TableCell className="pl-5 font-medium">{s.shop}</TableCell>
                                            <TableCell className={matchesBatches ? 'text-right' : 'pr-5 text-right'}>{qty(s.onHand)}</TableCell>
                                            {matchesBatches && (
                                                <TableCell className="pr-5 text-right">
                                                    {qty(s.batchQty)}
                                                    {s.batches > 0 && (
                                                        <span className="text-muted-foreground ml-1 text-xs">
                                                            ({s.batches} deliver{s.batches === 1 ? 'y' : 'ies'})
                                                        </span>
                                                    )}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </SectionCard>
                </div>
                <SectionCard title="Details" description="Each shop closes, reopens and returns stock at its own till.">
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
                        ]}
                    />
                </SectionCard>
            </div>

            {editing && <RecallDialog recall={recall} suppliers={suppliers} productResults={productResults} onClose={() => setEditing(false)} />}
        </AppLayout>
    );
}
