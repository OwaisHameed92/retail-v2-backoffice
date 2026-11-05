import { formatDay } from '@/components/admin/billing/format';
import { type SetupFeePart } from '@/components/admin/billing/types';
import { StatusBadge, type StatusToneMap } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

const tones: StatusToneMap = {
    paid: 'success',
    collected: 'success',
    scheduled: 'info',
    submitted: 'info',
    planned: 'neutral',
    waiting: 'warning',
    failed: 'danger',
    byHand: 'neutral',
};

/** What each part says next to its badge: the Direct Debit day, or why there is none yet. */
function when(part: SetupFeePart): string {
    switch (part.state) {
        case 'scheduled':
            return `With GoCardless, collected on ${formatDay(part.chargeDate)}`;
        case 'submitted':
            return `Collecting on ${formatDay(part.chargeDate)}`;
        case 'planned':
            return `Scheduled for ${formatDay(part.chargeDate)} (sent to GoCardless a few days before)`;
        case 'failed':
            return `Was due ${formatDay(part.chargeDate ?? part.dueDate)}: retry it, or record it by hand`;
        case 'waiting':
            return 'Collected once the mandate is set up';
        case 'paid':
            return part.chargeDate ? `Collected ${formatDay(part.chargeDate)}` : '';
        default:
            return '';
    }
}

/**
 * Owner 2026-11-05: the setup fee instalments of a business whose setup fee is collected by Direct Debit, each with
 * where it stands (scheduled for a day, submitted, paid, failed, waiting for the mandate).
 */
export function SetupFeeParts({ parts }: { parts: SetupFeePart[] }) {
    return (
        <div className="grid gap-2">
            <h3 className="text-sm font-semibold">Setup fee by Direct Debit</h3>
            <div className="-mx-5 overflow-x-auto sm:-mx-6">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-5 sm:pl-6">Part</TableHead>
                            <TableHead>Due</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead className="pr-5 sm:pr-6">Direct Debit</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {parts.map((part) => (
                            <TableRow key={part.number ?? part.label}>
                                <TableCell className="pl-5 sm:pl-6">
                                    <div className="font-medium">{part.label}</div>
                                    {part.number && <div className="text-muted-foreground font-mono text-xs">{part.number}</div>}
                                </TableCell>
                                <TableCell className="whitespace-nowrap">{formatDay(part.dueDate)}</TableCell>
                                <TableCell className="text-right font-medium tabular-nums">{part.amount}</TableCell>
                                <TableCell className="pr-5 sm:pr-6">
                                    <StatusBadge status={part.state} label={part.stateLabel} tones={tones} />
                                    {when(part) && <div className="text-muted-foreground mt-1 text-xs">{when(part)}</div>}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}

export default SetupFeeParts;
