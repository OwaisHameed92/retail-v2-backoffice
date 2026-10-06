import { money } from '@/components/app/purchasing/format';
import { SectionCard } from '@/components/shared/section-card';
import { cn } from '@/lib/utils';
import { CircleAlert, CircleCheck, CircleX, Info } from 'lucide-react';
import { type InvoiceAnalysis, type InvoiceDraft } from './types';
import { taxName } from '@/lib/country';

const ICONS = { error: CircleX, warning: CircleAlert, info: Info };
const TONES = { error: 'text-destructive', warning: 'text-warning', info: 'text-info' };

function Row({ label, lines, printed }: { label: string; lines: string; printed: string | null }) {
    const differs = printed !== null && Math.abs(Number(printed) - Number(lines)) > 0.02;

    return (
        <tr className="border-border border-b last:border-0">
            <th scope="row" className="py-2 pr-3 text-left font-normal">
                {label}
            </th>
            <td className="py-2 pr-3 text-right tabular-nums">{money(lines)}</td>
            <td className={cn('py-2 text-right tabular-nums', differs && 'text-warning-foreground font-medium')}>{printed !== null ? money(printed) : '—'}</td>
        </tr>
    );
}

/** The sums (lines against what is printed) and every check, worst first. */
export function ChecksCard({ analysis, draft }: { analysis: InvoiceAnalysis; draft: InvoiceDraft }) {
    const order = { error: 0, warning: 1, info: 2 };
    const issues = [...analysis.issues].sort((a, b) => order[a.level] - order[b.level]);

    return (
        <SectionCard title="Checks" description="Worked out from the lines, your products and the shop's order or delivery.">
            <div className="grid gap-4">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-muted-foreground text-xs">
                            <th className="pb-1 text-left font-medium" />
                            <th className="pb-1 text-right font-medium">Lines add up to</th>
                            <th className="pb-1 text-right font-medium">Printed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <Row label="Net" lines={analysis.totals.net} printed={draft.netTotal} />
                        <Row label={taxName()} lines={analysis.totals.vat} printed={draft.vatTotal} />
                        <Row label="Total" lines={analysis.totals.gross} printed={draft.grossTotal} />
                    </tbody>
                </table>

                {issues.length === 0 ? (
                    <p className="text-success-foreground flex items-center gap-2 text-sm">
                        <CircleCheck className="text-success size-4" />
                        Everything adds up and every line is matched.
                    </p>
                ) : (
                    <ul className="grid gap-2" aria-label="Checks">
                        {issues.map((issue, i) => {
                            const Icon = ICONS[issue.level];

                            return (
                                <li key={`${issue.code}-${i}`} className="flex items-start gap-2 text-sm">
                                    <Icon className={cn('mt-0.5 size-4 shrink-0', TONES[issue.level])} aria-label={issue.level} />
                                    <span>{issue.message}</span>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {draft.readingNotes.length > 0 && (
                    <div className="bg-muted/50 rounded-md px-3 py-2">
                        <p className="text-muted-foreground mb-1 text-xs font-medium">Noted while reading</p>
                        <ul className="text-muted-foreground grid gap-0.5 text-xs">
                            {draft.readingNotes.map((note, i) => (
                                <li key={i}>{note}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </SectionCard>
    );
}
