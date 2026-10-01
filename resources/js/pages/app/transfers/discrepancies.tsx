import { ProductCell } from '@/components/app/purchasing/parts';
import {
    cost,
    filterQuery,
    formatDateTime,
    lossClass,
    money,
    qty,
    signedMoney,
    signedQty,
    TransferFilterBar,
    TransferTabs,
    varianceClass,
} from '@/components/app/transfers/format';
import { type DiscrepancyProps } from '@/components/app/transfers/types';
import { useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CircleCheck, Download, Info, MinusCircle, PackageCheck, PlusCircle } from 'lucide-react';

const count = new Intl.NumberFormat('en-GB');

/** Transfer discrepancies across shops (module 5.3): received transfers in a period, per route and line by line, at cost. */
export default function TransferDiscrepancies(props: DiscrepancyProps) {
    const { filters, summary, routes, lines, lineCount, truncated, shops, oneShop } = props;
    const { update } = useTableQuery({ only: ['filters', 'summary', 'routes', 'lines', 'lineCount', 'truncated'] });
    const query = filterQuery(filters);

    return (
        <AppLayout>
            <Head title="Transfer discrepancies" />

            <PageHeader
                title="Stock transfers"
                description="Where what arrived at a shop differs from what was sent. Differences are the receiving till's own figures: per line received − sent (minus = short), and per receipt the value lost in transit (sent − received at cost)."
                actions={
                    <Button variant="outline" asChild>
                        <a href={route('app.transfers.discrepancies.export', query)}>
                            <Download />
                            Export CSV
                        </a>
                    </Button>
                }
                tabs={<TransferTabs current="discrepancies" query={filterQuery({ ...filters, status: null })} />}
            />

            {oneShop && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>You are seeing transfers from or to {shops[0]?.name ?? 'your shop'} only.</AlertDescription>
                </Alert>
            )}

            <TransferFilterBar filters={filters} shared={props} update={update} status={false} />

            <StatGrid columns={4}>
                <StatCard
                    label="Transfers received"
                    value={count.format(summary.transfers)}
                    hint={`${money(summary.sentValue)} sent at cost`}
                    icon={PackageCheck}
                />
                <StatCard
                    label="With discrepancies"
                    value={count.format(summary.discrepant)}
                    hint={
                        summary.transfers > 0
                            ? `${Math.round((summary.discrepant / summary.transfers) * 100)}% of transfers received`
                            : 'None received'
                    }
                    tone={summary.discrepant > 0 ? 'warning' : 'success'}
                    icon={AlertTriangle}
                />
                <StatCard
                    label="Units short / over"
                    value={`${qty(summary.short)} / ${qty(summary.over)}`}
                    hint="Short: sent but not received"
                    tone="neutral"
                    icon={MinusCircle}
                />
                <StatCard
                    label="Lost in transit at cost"
                    value={money(summary.varianceCost)}
                    hint="Sent less received, as the receiving tills report it"
                    tone={Number(summary.varianceCost) > 0 ? 'danger' : 'neutral'}
                    icon={PlusCircle}
                />
            </StatGrid>

            {summary.transfers === 0 ? (
                <SectionCard>
                    <EmptyState
                        icon={PackageCheck}
                        title="No transfers received in this period"
                        body="Pick other days, or check back once the shops have received transfers on their tills."
                    />
                </SectionCard>
            ) : (
                <>
                    <SectionCard title="By route" description="Worst first: the routes losing the most at cost." flush>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>From → to</TableHead>
                                    <TableHead className="text-right">Received</TableHead>
                                    <TableHead className="text-right">With discrepancies</TableHead>
                                    <TableHead className="text-right">Short</TableHead>
                                    <TableHead className="text-right">Over</TableHead>
                                    <TableHead className="text-right">Sent at cost</TableHead>
                                    <TableHead className="text-right">Lost in transit</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {routes.map((r) => (
                                    <TableRow key={`${r.from}>${r.to}`}>
                                        <TableCell>
                                            <span className="flex items-center gap-1.5">
                                                {r.from}
                                                <ArrowRight className="text-muted-foreground size-3.5" aria-label="to" />
                                                {r.to}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{count.format(r.transfers)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{count.format(r.discrepant)}</TableCell>
                                        <TableCell className={varianceClass(Number(r.short) > 0 ? '-1' : '0') + ' text-right'}>
                                            {qty(r.short)}
                                        </TableCell>
                                        <TableCell className={varianceClass(r.over) + ' text-right'}>{qty(r.over)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(r.sentValue)}</TableCell>
                                        <TableCell className={lossClass(r.varianceCost) + ' text-right'}>{money(r.varianceCost)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </SectionCard>

                    <SectionCard
                        title="Lines that differ"
                        description={
                            truncated
                                ? `The latest ${count.format(lines.length)} of ${count.format(lineCount)}. Export the CSV for all of them.`
                                : `${count.format(lineCount)} ${lineCount === 1 ? 'line' : 'lines'}, newest receipt first.`
                        }
                        flush
                    >
                        {lines.length === 0 ? (
                            <EmptyState
                                icon={CircleCheck}
                                title="Everything arrived as sent"
                                body="No received line differs from what was sent in this period."
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Transfer</TableHead>
                                        <TableHead>Product</TableHead>
                                        <TableHead className="text-right">Sent</TableHead>
                                        <TableHead className="text-right">Received</TableHead>
                                        <TableHead className="text-right">Received − sent</TableHead>
                                        <TableHead className="text-right">Unit cost</TableHead>
                                        <TableHead className="text-right">At cost</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {lines.map((line) => (
                                        <TableRow
                                            key={line.id}
                                            className="cursor-pointer"
                                            onClick={() => router.visit(route('app.transfers.show', line.transferId))}
                                        >
                                            <TableCell>
                                                <div className="grid leading-5">
                                                    <span className="font-mono text-sm font-medium">{line.reference}</span>
                                                    <span className="text-muted-foreground text-xs">
                                                        {line.from} → {line.to} · {formatDateTime(line.receivedAt)}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <ProductCell product={line.product} />
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">{qty(line.sent)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{qty(line.received)}</TableCell>
                                            <TableCell className={varianceClass(line.variance) + ' text-right'}>{signedQty(line.variance)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{cost(line.unitCost)}</TableCell>
                                            <TableCell className={varianceClass(line.varianceValue) + ' text-right'}>
                                                {signedMoney(line.varianceValue)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </SectionCard>
                </>
            )}
        </AppLayout>
    );
}
