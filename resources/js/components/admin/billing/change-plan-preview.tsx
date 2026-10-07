import { type PlanChangePreviewData } from '@/components/admin/billing/types';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AlertTriangle, ArrowRight, Mail, Receipt } from 'lucide-react';
import { type ReactNode } from 'react';

function Part({ title, lines, children }: { title: string; lines?: string[]; children?: ReactNode }) {
    return (
        <section className="grid gap-1.5">
            <h3 className="text-sm font-semibold">{title}</h3>
            {lines && lines.length > 0 && (
                <ul className="text-muted-foreground grid list-disc gap-1 pl-5 text-sm">
                    {lines.map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
            )}
            {children}
        </section>
    );
}

/** The plain-words preview of a plan change (PlanChangePreview): one part per thing that changes. */
export function ChangePlanPreview({ preview }: { preview: PlanChangePreviewData }) {
    if (preview.blocked) {
        return (
            <Alert variant="destructive">
                <AlertTriangle className="size-4" />
                <AlertTitle>This change cannot be made</AlertTitle>
                <AlertDescription>{preview.blocked}</AlertDescription>
            </Alert>
        );
    }

    return (
        <div className="grid gap-5">
            <div className="bg-subtle flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2.5 text-sm">
                <span className="font-medium">{preview.from?.name ?? 'No plan'}</span>
                {preview.from && <span className="text-muted-foreground">({preview.from.typeLabel})</span>}
                <ArrowRight className="text-muted-foreground size-4" aria-label="to" />
                <span className="font-medium">{preview.to.name}</span>
                <span className="text-muted-foreground">({preview.to.typeLabel})</span>
            </div>

            <Part title="Features" lines={preview.features.lines} />
            <Part title="Setup fee" lines={preview.setupFee.lines} />
            <Part title={preview.recurring.title} lines={preview.recurring.lines} />
            <Part title="Licences" lines={preview.licences.lines} />

            <Part title="Invoices created">
                {preview.invoices.length === 0 ? (
                    <p className="text-muted-foreground text-sm">None.</p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {preview.invoices.map((invoice) => (
                            <li key={invoice.title} className="flex items-start justify-between gap-3 px-3 py-2.5 text-sm">
                                <span className="flex min-w-0 items-start gap-2">
                                    <Receipt className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">{invoice.title}</span>
                                        <span className="text-muted-foreground">{invoice.when}</span>
                                    </span>
                                </span>
                                <span className="shrink-0 font-semibold tabular-nums">{invoice.amount}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Part>

            {preview.notes.length > 0 && (
                <div className="border-warning/40 bg-warning-soft text-warning-foreground grid gap-1.5 rounded-lg border px-3 py-2.5 text-sm">
                    {preview.notes.map((note) => (
                        <p key={note} className="flex items-start gap-2">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                            {note}
                        </p>
                    ))}
                </div>
            )}

            <p className="text-muted-foreground flex items-start gap-2 text-sm">
                <Mail className="mt-0.5 size-4 shrink-0" aria-hidden />
                {preview.email}
            </p>
        </div>
    );
}

export default ChangePlanPreview;
