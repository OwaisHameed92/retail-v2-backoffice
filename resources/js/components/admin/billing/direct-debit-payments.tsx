import { formatDay } from '@/components/admin/billing/format';
import { type DirectDebitPaymentRow } from '@/components/admin/billing/types';
import { EmptyState } from '@/components/shared/empty-state';
import { StatusBadge, type StatusToneMap } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link } from '@inertiajs/react';
import { Landmark } from 'lucide-react';

/** GoCardless mandate, subscription and payment statuses → pill tones. */
export const directDebitTones: StatusToneMap = {
    active: 'success',
    pendingSubmission: 'info',
    submitted: 'info',
    pendingCustomerApproval: 'warning',
    confirmed: 'success',
    paidOut: 'success',
    paused: 'warning',
    finished: 'neutral',
    failed: 'danger',
    chargedBack: 'danger',
    cancelled: 'danger',
    expired: 'danger',
    blocked: 'danger',
    consumed: 'neutral',
    suspendedByPayer: 'danger',
    customerApprovalDenied: 'danger',
};

/** Recent GoCardless payments of a business, each with the invoice it collects. */
export function DirectDebitPayments({ payments }: { payments: DirectDebitPaymentRow[] }) {
    return payments.length === 0 ? (
        <EmptyState
            icon={Landmark}
            size="sm"
            title="No Direct Debit payments yet"
            body="GoCardless payments appear here with their invoice as soon as they are created."
        />
    ) : (
        <div className="-mx-5 overflow-x-auto sm:-mx-6">
            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="pl-5 sm:pl-6">Collection</TableHead>
                        <TableHead>For</TableHead>
                        <TableHead className="hidden sm:table-cell">Invoice</TableHead>
                        <TableHead className="text-right">Amount</TableHead>
                        <TableHead className="pr-5 sm:pr-6">Status</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {payments.map((payment) => (
                        <TableRow key={payment.id}>
                            <TableCell className="pl-5 whitespace-nowrap sm:pl-6">
                                {formatDay(payment.chargeDate)}
                                <div className="text-muted-foreground font-mono text-xs">{payment.gcPaymentId}</div>
                            </TableCell>
                            <TableCell>
                                {payment.kind === 'setupFee' ? `Setup fee${payment.instalment ? ` (${payment.instalment})` : ''}` : 'Subscription'}
                                {payment.failureReason && (
                                    <div className="text-danger-foreground max-w-64 truncate text-xs">{payment.failureReason}</div>
                                )}
                            </TableCell>
                            <TableCell className="hidden sm:table-cell">
                                {payment.invoiceId ? (
                                    <Link
                                        href={route('admin.billing.invoices.show', payment.invoiceId)}
                                        className="text-primary font-mono text-[13px] font-medium hover:underline"
                                    >
                                        {payment.invoiceNumber ?? 'Draft'}
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground">—</span>
                                )}
                            </TableCell>
                            <TableCell className="text-right font-medium tabular-nums">{payment.amount}</TableCell>
                            <TableCell className="pr-5 sm:pr-6">
                                <StatusBadge status={payment.status} label={payment.statusLabel} tones={directDebitTones} />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
