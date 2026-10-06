import { copies, formatDateTime, formatDay, money, NewsStatus } from '@/components/app/news/format';
import { type DeliveryShowProps } from '@/components/app/news/types';
import { cost } from '@/components/app/purchasing/format';
import { DescriptionList } from '@/components/shared/description-list';
import { EmptyState } from '@/components/shared/empty-state';
import { MoneyIcon } from '@/components/shared/money-icon';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head } from '@inertiajs/react';
import { Eye, ListX, Newspaper, Undo2 } from 'lucide-react';

/** One news delivery (module 5.8), read only: the shop's own record, by title. */
export default function NewsDeliveryShow({ delivery, lines, totals }: DeliveryShowProps) {
    const title = `News delivery ${formatDay(delivery.date)}`;
    const awaiting = Number(totals.credit) > 0 && !delivery.creditPostedAt && delivery.status !== 'settled';

    return (
        <AppLayout>
            <Head title={title} />

            <PageHeader
                title={title}
                back={{ href: route('app.news.index', 'deliveries'), label: 'Deliveries' }}
                status={<NewsStatus status={delivery.status} />}
                description={[delivery.supplier, delivery.shop].filter(Boolean).join(' · ')}
            />

            <Alert>
                <Eye />
                <AlertDescription>Kept at the shop: this is its till's record, so it is read only here.</AlertDescription>
            </Alert>

            <StatGrid columns={4}>
                <StatCard label="Copies in" value={copies(totals.qtyIn)} hint={`${copies(totals.qtySold)} sold`} icon={Newspaper} tone="primary" />
                <StatCard
                    label="Returned"
                    value={copies(totals.qtyReturned)}
                    hint={`${money(totals.credit)} credit`}
                    icon={Undo2}
                    tone={awaiting ? 'warning' : 'neutral'}
                />
                <StatCard
                    label="Net cost"
                    value={money(totals.netCost)}
                    hint={`${money(totals.cost)} before returns`}
                    icon={MoneyIcon}
                    tone="neutral"
                />
                <StatCard
                    label="Margin"
                    value={money(totals.margin)}
                    hint={`On ${money(totals.sales)} sales at cover price`}
                    icon={MoneyIcon}
                    tone={Number(totals.margin) < 0 ? 'danger' : 'success'}
                />
            </StatGrid>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
                <SectionCard title="Titles" flush className="lg:col-span-2">
                    {lines.length === 0 ? (
                        <EmptyState icon={ListX} title="No titles" body="The shop recorded this delivery without lines." className="py-8" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Title</TableHead>
                                    <TableHead className="text-right">In</TableHead>
                                    <TableHead className="text-right">Sold</TableHead>
                                    <TableHead className="text-right">Returned</TableHead>
                                    <TableHead className="text-right">Unit cost</TableHead>
                                    <TableHead className="text-right">Cost</TableHead>
                                    <TableHead className="text-right">Credit</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell>
                                            <div className="grid leading-5">
                                                <span className="font-medium">{line.title}</span>
                                                {line.qtyUnaccounted > 0 && (
                                                    <span className="text-warning-foreground text-xs">
                                                        {copies(line.qtyUnaccounted)} not sold or returned
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{copies(line.qtyIn)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{copies(line.qtySold)}</TableCell>
                                        <TableCell className={cn('text-right tabular-nums', line.qtyReturned === 0 && 'text-muted-foreground')}>
                                            {copies(line.qtyReturned)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{cost(line.unitCost)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(line.cost)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(line.credit)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell className="font-medium">Total</TableCell>
                                    <TableCell className="text-right tabular-nums">{copies(totals.qtyIn)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{copies(totals.qtySold)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{copies(totals.qtyReturned)}</TableCell>
                                    <TableCell />
                                    <TableCell className="text-right tabular-nums">{money(totals.cost)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{money(totals.credit)}</TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    )}
                </SectionCard>

                <SectionCard title="Details">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Shop', value: delivery.shop ?? '—' },
                            { label: 'Wholesaler', value: delivery.supplier },
                            { label: 'Delivered', value: formatDay(delivery.date) },
                            {
                                label: 'Credit',
                                value: delivery.creditPostedAt
                                    ? `Posted ${formatDateTime(delivery.creditPostedAt)}`
                                    : awaiting
                                      ? 'Awaiting credit from the wholesaler'
                                      : 'Nothing returned yet',
                            },
                            ...(delivery.notes ? [{ label: 'Notes', value: delivery.notes }] : []),
                        ]}
                    />
                </SectionCard>
            </div>
        </AppLayout>
    );
}
