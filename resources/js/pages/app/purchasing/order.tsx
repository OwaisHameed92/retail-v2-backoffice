import { cost, formatDateTime, formatDay, money, PurchasingStatus, qty } from '@/components/app/purchasing/format';
import { LinkedCard, ProductCell, toLinks, Totals } from '@/components/app/purchasing/parts';
import { type OrderShowProps } from '@/components/app/purchasing/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DescriptionList } from '@/components/shared/description-list';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { Ban, Building2, Info, Lock, Pencil, Send } from 'lucide-react';
import { useState } from 'react';

/** One purchase order (module 5.2). A head-office order the portal still owns can be edited, sent or cancelled. */
export default function PurchaseOrderShow({ order, totals, lines, deliveries, invoices, lockedReason, can }: OrderShowProps) {
    const [reason, setReason] = useState('');
    const fromHeadOffice = order.origin === 'headOffice';
    const post = (name: 'app.purchasing.orders.send' | 'app.purchasing.orders.cancel', data: Record<string, string> = {}) =>
        new Promise((resolve) => router.post(route(name, order.id), data, { preserveScroll: true, onFinish: () => resolve(null) }));

    return (
        <AppLayout>
            <Head title={`Order ${order.reference}`} />

            <PageHeader
                title={order.reference}
                back={{ href: route('app.purchasing.index', 'orders'), label: 'Orders' }}
                status={
                    <span className="flex flex-wrap items-center gap-1.5">
                        <PurchasingStatus status={order.status} />
                        {fromHeadOffice && <StatusPill tone="violet">From head office</StatusPill>}
                    </span>
                }
                description={[order.supplier, order.shop].filter(Boolean).join(' · ')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.cancel && (
                            <ConfirmDialog
                                trigger={
                                    <Button variant="outline">
                                        <Ban />
                                        Cancel order
                                    </Button>
                                }
                                title={`Cancel ${order.reference}?`}
                                description={`${order.shop ?? 'The shop'} gets the cancelled order at its next sync. A cancelled order cannot be re-opened.`}
                                confirmLabel="Cancel order"
                                cancelLabel="Keep order"
                                destructive
                                onConfirm={() => post('app.purchasing.orders.cancel', { reason })}
                            >
                                <div className="grid gap-1.5">
                                    <Label htmlFor="cancel-reason">Reason (optional)</Label>
                                    <Textarea
                                        id="cancel-reason"
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        maxLength={500}
                                        rows={2}
                                    />
                                </div>
                            </ConfirmDialog>
                        )}
                        {can.edit && (
                            <Button variant="outline" asChild>
                                <Link href={route('app.purchasing.orders.edit', order.id)}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        {can.send && (
                            <ConfirmDialog
                                trigger={
                                    <Button>
                                        <Send />
                                        Send order
                                    </Button>
                                }
                                title={`Send ${order.reference}?`}
                                description={`Send it once it is placed with ${order.supplier}. ${order.shop ?? 'The shop'} can then book the delivery in against it.`}
                                confirmLabel="Send order"
                                onConfirm={() => post('app.purchasing.orders.send')}
                            />
                        )}
                    </div>
                }
            />

            {order.withPortal ? (
                <Alert variant="info">
                    <Building2 />
                    <AlertDescription>
                        {order.status === 'draft'
                            ? `A draft from head office: ${order.shop ?? 'the shop'} sees it at the next sync but cannot receive it until you send it.`
                            : `Sent from head office. ${order.shop ?? 'The shop'} can book the delivery in. You can still change or cancel it until the shop takes it on.`}
                    </AlertDescription>
                </Alert>
            ) : (
                lockedReason && (
                    <Alert>
                        {fromHeadOffice ? <Lock /> : <Info />}
                        <AlertDescription>{lockedReason}</AlertDescription>
                    </Alert>
                )
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
                <SectionCard
                    title="Lines"
                    description={`${qty(totals.received)} of ${qty(totals.ordered)} units received`}
                    flush
                    className="lg:col-span-2"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Product</TableHead>
                                <TableHead className="text-right">Ordered</TableHead>
                                <TableHead className="text-right">Received</TableHead>
                                <TableHead className="text-right">Unit cost</TableHead>
                                <TableHead className="text-right">Net</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((line) => {
                                const loose = Number(line.units) - line.cases * line.caseQty;
                                const short = Number(line.received) < Number(line.units);

                                return (
                                    <TableRow key={line.id}>
                                        <TableCell>
                                            <ProductCell product={line.product} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <div className="grid leading-5">
                                                <span>{qty(line.units)}</span>
                                                <span className="text-muted-foreground text-xs">
                                                    {line.cases} × {line.caseQty}
                                                    {loose > 0 ? ` + ${qty(loose)}` : ''}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right tabular-nums',
                                                Number(line.received) === 0 ? 'text-muted-foreground' : short && 'text-warning-foreground',
                                            )}
                                        >
                                            {qty(line.received)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <div className="grid leading-5">
                                                <span>{cost(line.unitCost)}</span>
                                                <span className="text-muted-foreground text-xs">VAT {qty(line.vatPercentage)}%</span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{money(line.net)}</TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                    <Totals
                        net={totals.net}
                        vat={totals.vat}
                        gross={totals.gross}
                        extra={totals.discount && Number(totals.discount) !== 0 ? [{ label: 'Discount', value: totals.discount }] : undefined}
                    />
                </SectionCard>

                <div className="grid gap-4">
                    <SectionCard title="Details">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Shop', value: order.shop },
                                { label: 'Supplier', value: order.supplier },
                                { label: 'Till number', value: order.orderNo, mono: true },
                                { label: 'Expected', value: order.expectedDate ? formatDay(order.expectedDate) : null },
                                { label: 'Created', value: formatDateTime(order.createdAt) },
                                { label: 'Sent', value: order.sentAt ? formatDateTime(order.sentAt) : null },
                                ...(order.cancelledAt ? [{ label: 'Cancelled', value: formatDateTime(order.cancelledAt) }] : []),
                                ...(order.cancelReason ? [{ label: 'Cancel reason', value: order.cancelReason }] : []),
                                { label: 'Notes', value: order.notes },
                            ]}
                        />
                    </SectionCard>
                    <LinkedCard
                        title="Deliveries and invoices"
                        links={[...toLinks('deliveries', 'Delivery', deliveries), ...toLinks('invoices', 'Invoice', invoices)]}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
