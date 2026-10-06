import { formatDate, formatDay } from '@/components/admin/billing/format';
import { InvoiceStatusBadge } from '@/components/admin/billing/invoice-status-badge';
import { type InvoiceStatus } from '@/components/admin/billing/types';
import { DescriptionList } from '@/components/shared/description-list';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { taxName } from '@/lib/country';
import { CalendarClock, CircleX, Wallet } from 'lucide-react';
import { type PortalBillingProps } from './types';

const accountTones = { trial: 'info', active: 'success', overdue: 'warning', suspended: 'danger', cancelled: 'neutral' } as const;

/** Module 4.10: the subscription at a glance, what is counted, and the business's requests to Switch & Save. */
export function SubscriptionCard({
    account,
    plan,
    pricing,
    requests,
    canRequest,
    onCancel,
}: Pick<PortalBillingProps, 'account' | 'plan' | 'pricing' | 'requests' | 'canRequest'> & { onCancel: () => void }) {
    const open = requests.filter((r) => !r.done);

    return (
        <SectionCard
            title="Your subscription"
            description="Tills and shops are counted as they are today. Switch & Save sets your plan and limits."
            actions={<StatusBadge status={account.status} label={account.statusLabel} tones={accountTones} />}
            footer={
                canRequest ? (
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-muted-foreground text-sm">
                            Your tills can only be cancelled by Switch & Save. Ask us and we will call you.
                        </p>
                        <Button variant="outline" size="sm" onClick={onCancel} disabled={open.some((r) => r.kind === 'cancel')}>
                            <CircleX />
                            {open.some((r) => r.kind === 'cancel') ? 'Cancellation requested' : 'Ask to cancel'}
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <DescriptionList
                columns={3}
                items={[
                    { label: 'Plan', value: plan ?? 'Standard' },
                    { label: 'Priced', value: pricing.unitPrice ? `${pricing.unitPrice} per ${pricing.unit} ${pricing.per}` : pricing.modeLabel },
                    { label: 'Billed', value: pricing.cycle === 'yearly' ? 'Yearly' : 'Monthly' },
                    { label: 'Tills counted', value: String(account.tills) },
                    { label: 'Shops', value: String(account.shops) },
                    account.trialEndsAt && account.status === 'trial'
                        ? { label: 'Free trial ends', value: formatDate(account.trialEndsAt) }
                        : { label: 'Customer since', value: account.customerSince ? formatDate(account.customerSince) : null },
                ]}
            />
            {requests.length > 0 && (
                <ul className="mt-5 grid gap-2 border-t pt-4">
                    {requests.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span>
                                {r.kindLabel} requested {formatDate(r.sentAt)}
                                {r.requestedBy ? ` by ${r.requestedBy}` : ''}
                                {r.count > 1 ? ` (asked ${r.count} times)` : ''}
                            </span>
                            <StatusPill tone={r.done ? 'success' : 'warning'}>{r.done ? 'Dealt with' : 'With Switch & Save'}</StatusPill>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}

/** Module 4.10: the one-off setup fee, in one payment or monthly instalments. */
export function SetupFeeCard({ setupFee }: { setupFee: NonNullable<PortalBillingProps['setupFee']> }) {
    const how = setupFee.charged
        ? 'Each part is its own invoice below. Pay by cash, card or bank transfer; it is never taken by Direct Debit.'
        : 'Paid by cash, card or bank transfer, never by Direct Debit. Your tills stay on the free trial until it is paid.';

    return (
        <SectionCard title="Setup fee" description={`${setupFee.total} in total, ${taxName()} included. ${how}`} flush contentClassName="p-0">
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-5 sm:pl-6">Part</TableHead>
                            <TableHead>Due</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead className="pr-5 sm:pr-6">Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {setupFee.parts.map((part) => (
                            <TableRow key={part.label + (part.number ?? '')}>
                                <TableCell className="pl-5 sm:pl-6">
                                    <div className="font-medium">{part.label}</div>
                                    {part.number && <div className="text-muted-foreground text-xs">{part.number}</div>}
                                </TableCell>
                                <TableCell>{part.dueDate ? formatDay(part.dueDate) : 'Not set yet'}</TableCell>
                                <TableCell className="text-right tabular-nums">{part.amount}</TableCell>
                                <TableCell className="pr-5 sm:pr-6">
                                    {part.status === 'planned' ? (
                                        <StatusPill tone="neutral">{part.statusLabel}</StatusPill>
                                    ) : (
                                        <InvoiceStatusBadge status={part.status as InvoiceStatus} />
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </SectionCard>
    );
}

/** Module 4.10: Direct Debit collections still to come, and every payment we received. */
export function PaymentsCard({ payments, collections }: Pick<PortalBillingProps, 'payments' | 'collections'>) {
    return (
        <SectionCard
            title="Payments"
            description="Collections scheduled by Direct Debit and every payment we have received from you."
            flush
            contentClassName="p-0"
        >
            {payments.length === 0 && collections.length === 0 ? (
                <EmptyState icon={Wallet} title="No payments yet" body="Your payments appear here as soon as we receive them." className="m-5" />
            ) : (
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5 sm:pl-6">Date</TableHead>
                                <TableHead>What</TableHead>
                                <TableHead className="text-right">Amount</TableHead>
                                <TableHead className="pr-5 sm:pr-6">Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {collections.map((c) => (
                                <TableRow key={c.id}>
                                    <TableCell className="pl-5 sm:pl-6">
                                        <span className="inline-flex items-center gap-1.5">
                                            <CalendarClock className="text-muted-foreground size-3.5" aria-hidden />
                                            {formatDay(c.chargeDate)}
                                        </span>
                                    </TableCell>
                                    <TableCell>{c.what} · Direct Debit</TableCell>
                                    <TableCell className="text-right tabular-nums">{c.amount}</TableCell>
                                    <TableCell className="pr-5 sm:pr-6">
                                        <StatusPill tone="info">{c.statusLabel}</StatusPill>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {payments.map((p) => (
                                <TableRow key={p.id}>
                                    <TableCell className="pl-5 sm:pl-6">{formatDate(p.receivedAt)}</TableCell>
                                    <TableCell>
                                        {p.method}
                                        <span className="text-muted-foreground"> · {p.number}</span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{p.amount}</TableCell>
                                    <TableCell className="pr-5 sm:pr-6">
                                        <StatusPill tone={p.reversed ? 'danger' : 'success'}>{p.reversed ? 'Returned' : 'Received'}</StatusPill>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </SectionCard>
    );
}
