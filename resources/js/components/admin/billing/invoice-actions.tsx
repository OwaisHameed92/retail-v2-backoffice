import { CreditNoteDialog } from '@/components/admin/billing/credit-note-dialog';
import { EditDraftDialog } from '@/components/admin/billing/edit-draft-dialog';
import { RecordPaymentDialog } from '@/components/admin/billing/record-payment-dialog';
import { type InvoiceDetail, type Option, type PaymentMethod } from '@/components/admin/billing/types';
import { VoidInvoiceDialog } from '@/components/admin/billing/void-invoice-dialog';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { RowActions, type RowAction } from '@/components/shared/row-actions';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Ban, Banknote, Download, FilePen, Mail, ReceiptText, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';

const METHODS: Option<PaymentMethod>[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'bankTransfer', label: 'Bank transfer' },
    { value: 'other', label: 'Other' },
];

type Dialog = 'issue' | 'edit' | 'delete' | 'pay' | 'send' | 'credit' | 'void' | null;

function post(url: string, options: { method?: 'post' | 'delete' } = {}) {
    return new Promise<void>((resolve) => {
        router.visit(url, { method: options.method ?? 'post', preserveScroll: true, onFinish: () => resolve() });
    });
}

function recipientsText(recipients: string[]): string {
    if (recipients.length === 0) {
        return 'Nobody yet: add a billing email or an owner first.';
    }

    return recipients.length === 1 ? recipients[0] : `${recipients.slice(0, -1).join(', ')} and ${recipients[recipients.length - 1]}`;
}

/** The invoice page's buttons: the primary action for its status, PDF download and a "…" menu. */
export function InvoiceActions({ invoice }: { invoice: InvoiceDetail }) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const close = (open: boolean) => !open && setDialog(null);
    const can = invoice.can;
    const pdf = route('admin.billing.invoices.pdf', invoice.id);

    const menu: RowAction[] = [
        { label: 'Send again', icon: Mail, onSelect: () => setDialog('send'), hidden: !can.send },
        { label: 'Add credit note', icon: ReceiptText, onSelect: () => setDialog('credit'), hidden: !can.credit },
        { label: 'Open PDF in a new tab', icon: Download, onSelect: () => window.open(`${pdf}?inline=1`, '_blank', 'noopener'), hidden: false },
        { label: 'Void invoice', icon: Ban, onSelect: () => setDialog('void'), destructive: true, hidden: !can.void },
        { label: 'Delete draft', icon: Trash2, onSelect: () => setDialog('delete'), destructive: true, hidden: !can.delete },
    ];

    return (
        <>
            <Button variant="outline" asChild>
                <a href={pdf} download>
                    <Download />
                    PDF
                </a>
            </Button>

            {can.edit && (
                <Button variant="outline" onClick={() => setDialog('edit')}>
                    <FilePen />
                    Edit
                </Button>
            )}
            {can.issue && (
                <Button onClick={() => setDialog('issue')}>
                    <Send />
                    Issue invoice
                </Button>
            )}
            {can.recordPayment && (
                <Button onClick={() => setDialog('pay')}>
                    <Banknote />
                    Record payment
                </Button>
            )}

            <RowActions actions={menu} label={`More actions for ${invoice.displayNumber}`} />

            <ConfirmDialog
                open={dialog === 'issue'}
                onOpenChange={close}
                title="Issue this invoice?"
                description={
                    <>
                        It gets the next invoice number, is dated today and can no longer be edited. The PDF is emailed to {recipientsText(invoice.recipients)}.
                        Total {invoice.total}.
                    </>
                }
                confirmLabel="Issue and email"
                onConfirm={() => post(route('admin.billing.invoices.issue', invoice.id))}
            />

            <ConfirmDialog
                open={dialog === 'send'}
                onOpenChange={close}
                title={`Send ${invoice.displayNumber} again?`}
                description={<>The invoice and its PDF go to {recipientsText(invoice.recipients)}.</>}
                confirmLabel="Send email"
                onConfirm={() => post(route('admin.billing.invoices.send', invoice.id))}
            />

            <ConfirmDialog
                open={dialog === 'delete'}
                onOpenChange={close}
                title="Delete this draft?"
                description={`The draft for ${invoice.company.name} (${invoice.period}) is removed. It has no number yet, so nothing is lost from the invoice sequence.`}
                confirmLabel="Delete draft"
                destructive
                onConfirm={() => post(route('admin.billing.invoices.destroy', invoice.id), { method: 'delete' })}
            />

            {can.edit && <EditDraftDialog open={dialog === 'edit'} onOpenChange={close} invoice={invoice} />}
            {can.recordPayment && (
                <RecordPaymentDialog open={dialog === 'pay'} onOpenChange={close} company={invoice.company} methods={METHODS} invoiceId={invoice.id} />
            )}
            {can.credit && (
                <CreditNoteDialog
                    open={dialog === 'credit'}
                    onOpenChange={close}
                    invoice={{ id: invoice.id, number: invoice.number, balance: invoice.balance, maxCredit: invoice.maxCredit, vatRate: invoice.vatRate }}
                />
            )}
            {can.void && (
                <VoidInvoiceDialog
                    open={dialog === 'void'}
                    onOpenChange={close}
                    invoice={{ id: invoice.id, number: invoice.number, amountPaid: invoice.amountPaid, hasPayments: invoice.hasPayments, companyName: invoice.company.name }}
                />
            )}
        </>
    );
}

export default InvoiceActions;
