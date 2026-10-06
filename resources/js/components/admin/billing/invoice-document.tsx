import { InvoiceStatusBadge } from '@/components/admin/billing/invoice-status-badge';
import { type InvoiceDocumentData } from '@/components/admin/billing/types';
import BrandLogo from '@/components/brand-logo';
import { companyNumberLabel, keepsUkStyles, taxName, vatNumberPrefix } from '@/lib/country';
import { cn } from '@/lib/utils';

function Label({ children }: { children: string }) {
    return <p className="text-muted-foreground mb-1.5 text-[11px] font-semibold tracking-[0.06em] uppercase">{children}</p>;
}

function Row({ label, value, strong = false, accent = false }: { label: string; value: string; strong?: boolean; accent?: boolean }) {
    return (
        <div className={cn('flex items-baseline justify-between gap-6 py-1', strong && 'border-foreground mt-1 border-t-[1.5px] pt-2 text-base font-semibold', accent && 'text-primary font-semibold')}>
            <dt className={cn(!strong && !accent && 'text-muted-foreground')}>{label}</dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );
}

/**
 * The invoice as the customer sees it (same content as the PDF): paper-like card, brand header, parties, lines,
 * totals, payments and how to pay. Scrolls sideways on phones rather than squashing the line table.
 */
export function InvoiceDocument({ doc }: { doc: InvoiceDocumentData }) {
    const stamp = doc.status === 'draft' ? 'Draft' : doc.status === 'void' ? 'Void' : doc.status === 'paid' ? 'Paid' : null;

    return (
        <article aria-label={doc.title} className="bg-card shadow-raised relative overflow-hidden rounded-xl border">
            {stamp && (
                <div aria-hidden className="pointer-events-none absolute inset-0 flex items-center justify-center overflow-hidden">
                    <span className="text-foreground/[0.045] -rotate-[24deg] text-[7rem] font-black tracking-[0.12em] uppercase select-none">{stamp}</span>
                </div>
            )}
            <div className="bg-primary h-1" aria-hidden />
            <div className="relative grid gap-8 p-5 sm:p-8">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1.5">
                        <BrandLogo className="h-7 w-auto" />
                        <p className="text-muted-foreground text-xs">Smart Solutions for Smart Businesses</p>
                    </div>
                    <div className="space-y-1 sm:text-right">
                        <h2 className="text-2xl font-semibold tracking-[-0.02em]">Invoice</h2>
                        <p className="font-mono text-sm">{doc.number ?? 'Draft, not yet issued'}</p>
                        <InvoiceStatusBadge status={doc.status} />
                    </div>
                </header>

                <section className="grid grid-cols-1 gap-6 text-sm sm:grid-cols-3">
                    <div className="min-w-0">
                        <Label>From</Label>
                        <p className="font-semibold">{doc.seller.legalName}</p>
                        {doc.seller.address.map((line) => (
                            <p key={line}>{line}</p>
                        ))}
                        {doc.seller.email && <p className="text-muted-foreground">{doc.seller.email}</p>}
                        {doc.seller.phone && <p className="text-muted-foreground">{doc.seller.phone}</p>}
                        {doc.seller.vatNumber && <p className="text-muted-foreground">{vatNumberPrefix()} {doc.seller.vatNumber}</p>}
                    </div>
                    <div className="min-w-0">
                        <Label>Bill to</Label>
                        <p className="font-semibold">{doc.billTo.name}</p>
                        {doc.billTo.address.length > 0 ? (
                            doc.billTo.address.map((line) => <p key={line}>{line}</p>)
                        ) : (
                            <p className="text-muted-foreground">No billing address</p>
                        )}
                        {doc.billTo.vatNumber && <p className="text-muted-foreground">{vatNumberPrefix()} {doc.billTo.vatNumber}</p>}
                    </div>
                    <dl className="grid content-start gap-1">
                        {[
                            ['Invoice date', doc.issueDate ?? 'Not issued'],
                            ['Due date', doc.dueDate ?? '—'],
                            ['Period', doc.period],
                            ['Billing', doc.cycle],
                        ].map(([label, value]) => (
                            <div key={label} className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">{label}</dt>
                                <dd className="text-right tabular-nums">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </section>

                <section className="-mx-5 overflow-x-auto px-5 sm:mx-0 sm:px-0">
                    <table className="w-full min-w-[34rem] text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left text-[11px] font-semibold tracking-[0.05em] uppercase">
                                <th className="py-2 pr-3 font-semibold">Description</th>
                                <th className="px-3 py-2 text-right font-semibold">Qty</th>
                                <th className="px-3 py-2 text-right font-semibold">Unit price</th>
                                {doc.hasVat && <th className="px-3 py-2 text-right font-semibold">{taxName()} {doc.vatRate}</th>}
                                <th className="py-2 pl-3 text-right font-semibold">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {doc.lines.map((line) => (
                                <tr key={line.id} className="border-b last:border-b-0">
                                    <td className="py-2.5 pr-3 align-top">{line.description}</td>
                                    <td className="px-3 py-2.5 text-right align-top tabular-nums">{line.quantity}</td>
                                    <td className="px-3 py-2.5 text-right align-top tabular-nums">{line.unitPrice}</td>
                                    {doc.hasVat && <td className="px-3 py-2.5 text-right align-top tabular-nums">{line.vat}</td>}
                                    <td className="py-2.5 pl-3 text-right align-top tabular-nums">{line.gross}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section className="flex justify-end">
                    <dl className="w-full max-w-xs text-sm">
                        <Row label="Subtotal" value={doc.subtotal} />
                        {doc.hasVat && <Row label={`${taxName()} at ${doc.vatRate}`} value={doc.vatTotal} />}
                        <Row label="Total" value={doc.total} strong />
                        {doc.hasPayments && <Row label="Paid" value={`−${doc.amountPaid}`} />}
                        {doc.hasCredits && <Row label="Credited" value={`−${doc.amountCredited}`} />}
                        {doc.status !== 'void' && <Row label={doc.status === 'paid' ? 'Balance' : 'Amount due'} value={doc.balance} accent />}
                    </dl>
                </section>

                {doc.status === 'void' && (
                    <div className="bg-muted rounded-lg px-4 py-3 text-sm">
                        <p className="font-medium">This invoice is void{doc.voidedOn ? ` since ${doc.voidedOn}` : ''}. Nothing is owed on it.</p>
                        {doc.voidReason && <p className="text-muted-foreground">Reason: {doc.voidReason}</p>}
                    </div>
                )}

                {(doc.payments.length > 0 || doc.creditNotes.length > 0) && (
                    <section>
                        <Label>Payments and credits</Label>
                        <ul className="divide-y rounded-lg border text-sm">
                            {doc.payments.map((payment, index) => (
                                <li key={`p-${index}`} className="flex items-center justify-between gap-3 px-3 py-2">
                                    <span>
                                        {payment.date} · {payment.method} payment <span className="font-mono text-[13px]">{payment.number}</span>
                                    </span>
                                    <span className="tabular-nums">{payment.amount}</span>
                                </li>
                            ))}
                            {doc.creditNotes.map((note) => (
                                <li key={note.number} className="flex items-center justify-between gap-3 px-3 py-2">
                                    <span className="min-w-0">
                                        {note.date} · Credit note <span className="font-mono text-[13px]">{note.number}</span>: {note.reason}
                                    </span>
                                    <span className="shrink-0 tabular-nums">{note.total}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {doc.status !== 'paid' && doc.status !== 'void' ? (
                    <section className="bg-subtle rounded-lg border px-4 py-3 text-sm">
                        <Label>How to pay</Label>
                        <p>
                            We take cash, or pay by bank transfer quoting <span className="font-semibold">{doc.reference ?? 'the invoice number'}</span> as the reference.
                            The licences are renewed as soon as the invoice is paid.
                        </p>
                        {doc.bank.length > 0 && (
                            <p className="mt-2 grid tabular-nums">
                                {doc.bank.map((line) => (
                                    <span key={line}>{line}</span>
                                ))}
                            </p>
                        )}
                    </section>
                ) : doc.status === 'paid' ? (
                    <section className="bg-success-soft text-success-foreground rounded-lg px-4 py-3 text-sm font-medium">
                        Paid in full{doc.paidOn ? ` on ${doc.paidOn}` : ''}. Thank you.
                    </section>
                ) : null}

                {doc.notes && (
                    <section className="text-sm">
                        <Label>Notes</Label>
                        <p className="whitespace-pre-line">{doc.notes}</p>
                    </section>
                )}

                <footer className="text-muted-foreground border-t pt-4 text-center text-xs">
                    {[doc.seller.legalName, doc.seller.companyNumber && `${keepsUkStyles() ? 'Company no.' : companyNumberLabel()} ${doc.seller.companyNumber}`, doc.seller.vatNumber && `${vatNumberPrefix()} ${doc.seller.vatNumber}`, doc.seller.email]
                        .filter(Boolean)
                        .join(' · ')}
                </footer>
            </div>
        </article>
    );
}

export default InvoiceDocument;
