import { invoiceStatusHelp, invoiceStatusLabels, invoiceStatusTones } from '@/components/admin/billing/format';
import { type InvoiceStatus } from '@/components/admin/billing/types';
import { StatusBadge } from '@/components/shared/status-badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

/** Invoice status pill with a tooltip explaining the status (and screen-reader text). */
export function InvoiceStatusBadge({ status, withHelp = false, className }: { status: InvoiceStatus; withHelp?: boolean; className?: string }) {
    const badge = <StatusBadge status={status} tones={invoiceStatusTones} label={invoiceStatusLabels[status]} className={className} />;

    if (!withHelp) {
        return badge;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span tabIndex={0} className="focus-visible:ring-ring/40 rounded-full outline-none focus-visible:ring-2">
                    {badge}
                    <span className="sr-only">. {invoiceStatusHelp[status]}</span>
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-64">{invoiceStatusHelp[status]}</TooltipContent>
        </Tooltip>
    );
}

export default InvoiceStatusBadge;
