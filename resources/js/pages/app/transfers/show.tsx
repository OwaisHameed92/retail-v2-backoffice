import { ProductCell } from '@/components/app/purchasing/parts';
import {
    cost,
    formatDateTime,
    money,
    qty,
    RELAY_LABELS,
    RelayPill,
    signedMoney,
    signedQty,
    TransferStatusBadge,
    varianceClass,
} from '@/components/app/transfers/format';
import { type RelayState, type TransferShowProps } from '@/components/app/transfers/types';
import { DescriptionList } from '@/components/shared/description-list';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Timeline, type TimelineItem } from '@/components/shared/timeline';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CircleCheck, CloudDownload, PackageCheck, Send, SquarePen } from 'lucide-react';

const RELAY_TEXT: Record<RelayState, (shop: string) => string> = {
    notRelayed: () => 'Not sent to any till: only dispatched transfers are.',
    waiting: (shop) => `Waiting for ${shop}'s till to sync.`,
    sent: (shop) => `Sent to ${shop}'s till at its last sync.`,
    stored: (shop) => `${shop}'s till has it.`,
    received: (shop) => `${shop} has received it.`,
};

/** One stock transfer (module 5.3), read only: sent against received line by line, the receipt and the relay. */
export default function TransferShow({ transfer, receipt, lines, totals, relay }: TransferShowProps) {
    const hasReceipt = receipt !== null;
    const moving = transfer.status === 'dispatched' || transfer.status === 'inTransit';
    const journey: TimelineItem[] = [
        {
            id: 'raised',
            icon: SquarePen,
            tone: 'neutral',
            title: `Raised at ${transfer.from}${transfer.requestedBy ? ` by ${transfer.requestedBy}` : ''}`,
            time: formatDateTime(transfer.requestedAt),
        },
        ...(transfer.dispatchedAt
            ? [
                  {
                      id: 'dispatched',
                      icon: Send,
                      tone: 'primary' as const,
                      title: `Dispatched${transfer.dispatchedBy ? ` by ${transfer.dispatchedBy}` : ''}`,
                      time: formatDateTime(transfer.dispatchedAt),
                  },
              ]
            : []),
        ...(relay.transfer !== 'notRelayed'
            ? [
                  {
                      id: 'relay',
                      icon: CloudDownload,
                      tone: relay.transfer === 'waiting' ? ('warning' as const) : ('success' as const),
                      title: RELAY_TEXT[relay.transfer](relay.toShop),
                      time: relay.toLastPullAt ? `Last sync ${formatDateTime(relay.toLastPullAt)}` : 'Not synced yet',
                  },
              ]
            : []),
        ...(receipt
            ? [
                  {
                      id: 'received',
                      icon: PackageCheck,
                      tone: totals.discrepancies > 0 ? ('warning' as const) : ('success' as const),
                      title: `Received at ${transfer.to}${receipt.receivedBy ? ` by ${receipt.receivedBy}` : ''}`,
                      time: formatDateTime(receipt.receivedAt),
                      body:
                          totals.discrepancies > 0
                              ? `${totals.discrepancies} ${totals.discrepancies === 1 ? 'line differs' : 'lines differ'} from what was sent.`
                              : undefined,
                  },
                  {
                      id: 'receipt-relay',
                      icon: CircleCheck,
                      tone: relay.receipt === 'waiting' ? ('warning' as const) : ('success' as const),
                      title: `Receipt: ${RELAY_TEXT[relay.receipt](relay.fromShop).toLowerCase()}`,
                      time: relay.fromLastPullAt ? `Last sync ${formatDateTime(relay.fromLastPullAt)}` : 'Not synced yet',
                  },
              ]
            : []),
    ];

    return (
        <AppLayout>
            <Head title={`Transfer ${transfer.reference}`} />

            <PageHeader
                title={transfer.reference}
                back={{ href: route('app.transfers.index'), label: 'Stock transfers' }}
                status={
                    <span className="flex flex-wrap items-center gap-1.5">
                        <TransferStatusBadge status={transfer.status} />
                        {transfer.isReturn && <StatusPill tone="violet">Return</StatusPill>}
                        {totals.discrepancies > 0 && <StatusPill tone="danger">Discrepancy</StatusPill>}
                    </span>
                }
                description={`${transfer.from} → ${transfer.to}`}
            />

            {moving && relay.transfer === 'waiting' && (
                <Alert variant="warning">
                    <CloudDownload />
                    <AlertDescription>
                        {transfer.to}&apos;s till has not pulled this transfer yet. It is sent at the till&apos;s next sync; until then the shop
                        cannot receive it.
                    </AlertDescription>
                </Alert>
            )}
            {totals.discrepancies > 0 && totals.varianceValue !== null && (
                <Alert variant="destructive">
                    <AlertTriangle />
                    <AlertDescription>
                        {totals.discrepancies} {totals.discrepancies === 1 ? 'line' : 'lines'} arrived different from what was sent:{' '}
                        {signedQty(totals.variance)} units, {signedMoney(totals.varianceValue)} at cost.
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid gap-4 lg:grid-cols-3 lg:items-start">
                <SectionCard
                    title="Lines"
                    description={
                        hasReceipt
                            ? `${qty(totals.received)} of ${qty(totals.sent)} units received`
                            : `${qty(totals.sent)} units sent, not received yet`
                    }
                    flush
                    className="lg:col-span-2"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Product</TableHead>
                                <TableHead className="text-right">Sent</TableHead>
                                <TableHead className="text-right">Received</TableHead>
                                <TableHead className="text-right">Difference</TableHead>
                                <TableHead className="text-right">Unit cost</TableHead>
                                <TableHead className="text-right">Value sent</TableHead>
                                <TableHead className="text-right">Difference at cost</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((line) => (
                                <TableRow key={line.id} className={cn(line.discrepancy && 'bg-danger-soft/60 hover:bg-danger-soft')}>
                                    <TableCell>
                                        <ProductCell
                                            product={line.product}
                                            note={line.requested !== null && line.requested !== line.sent ? `${qty(line.requested)} requested` : null}
                                            flag={line.missingOnReceipt ? 'Not on the receipt' : null}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{qty(line.sent)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{qty(line.received)}</TableCell>
                                    <TableCell className={cn('text-right', varianceClass(line.variance))}>{signedQty(line.variance)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{cost(line.unitCost)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{money(line.sentValue)}</TableCell>
                                    <TableCell className={cn('text-right', varianceClass(line.varianceValue))}>
                                        {signedMoney(line.varianceValue)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell className="font-medium">Total</TableCell>
                                <TableCell className="text-right tabular-nums">{qty(totals.sent)}</TableCell>
                                <TableCell className="text-right tabular-nums">{qty(totals.received)}</TableCell>
                                <TableCell className={cn('text-right', varianceClass(totals.variance))}>{signedQty(totals.variance)}</TableCell>
                                <TableCell />
                                <TableCell className="text-right tabular-nums">{money(totals.sentValue)}</TableCell>
                                <TableCell className={cn('text-right', varianceClass(totals.varianceValue))}>
                                    {signedMoney(totals.varianceValue)}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </SectionCard>

                <div className="grid gap-4">
                    <SectionCard title="Details">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'From', value: transfer.from },
                                { label: 'To', value: transfer.to },
                                { label: 'Value at cost', value: money(transfer.dispatchedCost) },
                                { label: 'Received value', value: totals.receivedValue === null ? null : money(totals.receivedValue) },
                                { label: 'Receiving till', value: <RelayPill state={relay.transfer} /> },
                                ...(hasReceipt ? [{ label: 'Receipt at the sender', value: RELAY_LABELS[relay.receipt] }] : []),
                                ...(transfer.returnOf
                                    ? [
                                          {
                                              label: 'Return of',
                                              value: (
                                                  <Link
                                                      className="text-primary font-mono hover:underline"
                                                      href={route('app.transfers.show', transfer.returnOf.id)}
                                                  >
                                                      {transfer.returnOf.reference}
                                                  </Link>
                                              ),
                                          },
                                      ]
                                    : []),
                                { label: 'Note', value: transfer.note },
                                ...(receipt?.note ? [{ label: 'Receipt note', value: receipt.note }] : []),
                            ]}
                        />
                    </SectionCard>
                    <SectionCard title="Journey">
                        <Timeline items={journey} />
                    </SectionCard>
                </div>
            </div>
        </AppLayout>
    );
}
