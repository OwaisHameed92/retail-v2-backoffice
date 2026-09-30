import { DescriptionList } from '@/components/shared/description-list';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Timeline, type TimelineItem } from '@/components/shared/timeline';
import { Link } from '@inertiajs/react';
import { Banknote, Coins, CreditCard, History, PiggyBank, ShieldCheck, Ticket, Wallet, XCircle } from 'lucide-react';
import { actionLabel, Amount, formatDateTime, money, SaleKindPill } from './format';
import { type ReceiptPayment, type SaleShowProps } from './types';

const PAYMENT_ICONS = { cash: Banknote, card: CreditCard, points: Coins, deposit: PiggyBank, voucher: Ticket, account: Wallet, other: Wallet };

function CustomerLink({ customer, canView }: { customer: { id: string; name: string } | null; canView: boolean }) {
    if (!customer) {
        return null;
    }

    return canView ? (
        <Link href={route('app.customers.show', customer.id)} className="hover:underline">
            {customer.name}
        </Link>
    ) : (
        <>{customer.name}</>
    );
}

function PaymentRow({ payment, canViewCustomers }: { payment: ReceiptPayment; canViewCustomers: boolean }) {
    const Icon = PAYMENT_ICONS[payment.kind];
    const facts = [
        payment.scheme && payment.last4 ? `${payment.scheme} •••• ${payment.last4}` : payment.last4 ? `•••• ${payment.last4}` : null,
        payment.authCode ? `Auth ${payment.authCode}` : null,
        payment.reference ? `Ref ${payment.reference}` : null,
        payment.currency ? `${payment.currency.code} ${payment.currency.amount} at ${payment.currency.rate}` : null,
    ].filter(Boolean);

    return (
        <li className="flex gap-3 py-2.5">
            <span className="bg-muted text-muted-foreground mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full">
                <Icon className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex items-baseline justify-between gap-3">
                    <span className="font-medium">{payment.name}</span>
                    <Amount value={payment.amount} className="font-medium" />
                </div>
                {payment.kind === 'points' && (
                    <p className="text-muted-foreground text-xs">
                        Points from <CustomerLink customer={payment.pointsCustomer} canView={canViewCustomers} />
                        {!payment.pointsCustomer && 'the customer'}
                    </p>
                )}
                {payment.kind === 'deposit' && <p className="text-muted-foreground text-xs">Paid earlier as an order deposit</p>}
                {facts.length > 0 && <p className="text-muted-foreground truncate text-xs">{facts.join(' · ')}</p>}
                <div className="mt-1 flex flex-wrap gap-1">
                    {payment.status && payment.status !== 'approved' && (
                        <StatusPill tone={payment.status === 'reversed' ? 'danger' : 'info'}>
                            {payment.status === 'reversed' ? 'Reversed' : 'Recovered'}
                        </StatusPill>
                    )}
                    {payment.offline && <StatusPill tone="warning">Taken offline</StatusPill>}
                    {Number(payment.cashback) !== 0 && <StatusPill tone="neutral">Cashback {money(payment.cashback)}</StatusPill>}
                    {Number(payment.change) !== 0 && <StatusPill tone="neutral">Change {money(payment.change)}</StatusPill>}
                </div>
            </div>
        </li>
    );
}

export function PaymentsCard({ payments, totals, canViewCustomers }: Pick<SaleShowProps, 'payments' | 'totals' | 'canViewCustomers'>) {
    return (
        <SectionCard title="Payments" description={payments.length ? undefined : 'No payment on this receipt'}>
            {payments.length > 0 && (
                <>
                    <ul className="divide-y">
                        {payments.map((p) => (
                            <PaymentRow key={p.id} payment={p} canViewCustomers={canViewCustomers} />
                        ))}
                    </ul>
                    <div className="mt-2 grid gap-1 border-t pt-3 text-sm">
                        <div className="flex justify-between">
                            <span>Tendered</span>
                            <Amount value={totals.tendered} />
                        </div>
                        {Number(totals.cashback) !== 0 && (
                            <div className="text-muted-foreground flex justify-between">
                                <span>Cashback</span>
                                <Amount value={totals.cashback} />
                            </div>
                        )}
                        <div className="flex justify-between font-medium">
                            <span>Change given</span>
                            <Amount value={totals.change} />
                        </div>
                    </div>
                </>
            )}
        </SectionCard>
    );
}

export function DetailsCard({ sale, canViewCustomers }: Pick<SaleShowProps, 'sale' | 'canViewCustomers'>) {
    return (
        <SectionCard title="Details">
            <DescriptionList
                layout="rows"
                items={[
                    { label: 'Shop', value: sale.shop ?? 'Unknown shop' },
                    { label: 'Till', value: sale.till ?? 'Unknown till' },
                    { label: 'Served by', value: sale.staff ?? 'Unknown staff member' },
                    {
                        label: 'Customer',
                        value: sale.customer ? <CustomerLink customer={sale.customer} canView={canViewCustomers} /> : 'No customer',
                    },
                    ...(sale.customer?.cardNo ? [{ label: 'Card', value: sale.customer.cardNo, mono: true }] : []),
                    { label: sale.status === 'voided' ? 'Voided' : 'Completed', value: formatDateTime(sale.voidedAt ?? sale.completedAt) },
                    ...(sale.approvedBy ? [{ label: 'Approved by', value: sale.approvedBy }] : []),
                    ...(sale.reason ? [{ label: 'Reason', value: sale.reason }] : []),
                    ...(sale.voidedBy ? [{ label: 'Voided by', value: sale.voidedBy }] : []),
                    ...(sale.voidReason ? [{ label: 'Void reason', value: sale.voidReason }] : []),
                    ...(sale.refundPriceBasis ? [{ label: 'Refund price', value: sale.refundPriceBasis }] : []),
                    ...(sale.noReceipt ? [{ label: 'Printed receipt', value: 'Not printed' }] : []),
                    { label: 'Sale number', value: `#${sale.number}`, mono: true },
                    { label: 'Reached the portal', value: formatDateTime(sale.receivedAt) },
                ]}
            />
        </SectionCard>
    );
}

export function LinkedCard({ original, linked }: Pick<SaleShowProps, 'original' | 'linked'>) {
    if (!original && linked.length === 0) {
        return null;
    }
    const rows = [...(original ? [{ ...original, relation: 'Original sale' }] : []), ...linked.map((s) => ({ ...s, relation: 'Against this sale' }))];

    return (
        <SectionCard
            title="Refunds and exchanges"
            description={original ? 'This receipt refers back to an earlier sale.' : 'Receipts made against this sale.'}
        >
            <ul className="divide-y">
                {rows.map((row) => (
                    <li key={row.id} className="flex items-center justify-between gap-3 py-2.5">
                        <div className="grid min-w-0">
                            <Link href={route('app.sales.show', row.id)} className="truncate font-medium hover:underline">
                                {row.receiptNumber}
                            </Link>
                            <span className="text-muted-foreground text-xs">
                                {row.relation} · {formatDateTime(row.at)}
                            </span>
                        </div>
                        <div className="flex items-center gap-2">
                            <SaleKindPill type={row.type} status={row.status} />
                            <Amount value={row.total} voided={row.status === 'voided'} />
                        </div>
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}

export function ActivityCard({ events }: Pick<SaleShowProps, 'events'>) {
    const items: TimelineItem[] = [...events].reverse().map((e) => ({
        id: e.id,
        icon: e.action === 'LineVoided' ? XCircle : e.action === 'RefundApproved' ? ShieldCheck : History,
        tone: e.action === 'LineVoided' ? 'warning' : e.action === 'RefundApproved' ? 'primary' : 'neutral',
        title: (
            <>
                <strong>{actionLabel(e.action)}</strong>
                {e.user && <> by {e.user}</>}
            </>
        ),
        time: formatDateTime(e.at),
        body:
            e.details.length > 0 || e.reason || e.matchedByTime ? (
                <div className="text-muted-foreground grid gap-0.5 text-xs">
                    {e.reason && <span>Reason: {e.reason}</span>}
                    {e.details.map((d) => (
                        <span key={d.label}>
                            {d.label}: {d.value}
                        </span>
                    ))}
                    {e.matchedByTime && <span className="italic">On this till while the basket was open</span>}
                </div>
            ) : undefined,
    }));

    return (
        <SectionCard title="Till activity" description="What the till logged about this basket">
            <Timeline items={items} emptyTitle="Nothing logged" emptyBody="No voided lines, approvals or other till events for this receipt." />
        </SectionCard>
    );
}
