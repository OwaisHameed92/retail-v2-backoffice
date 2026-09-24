import { licenceStatusHelp, licenceStatusLabels, licenceStatusTones } from '@/components/admin/licences/format';
import { type LicenceStatus } from '@/components/admin/licences/types';
import { StatusBadge } from '@/components/shared/status-badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

interface LicenceStatusBadgeProps {
    status: LicenceStatus;
    /** Why the till is locked, e.g. "The branch is deactivated." Shown in a tooltip and to screen readers. */
    reason?: string | null;
    className?: string;
}

/** Effective licence status with its meaning (and the lock reason) on hover and focus. */
export function LicenceStatusBadge({ status, reason, className }: LicenceStatusBadgeProps) {
    const help = reason ?? licenceStatusHelp[status];

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span tabIndex={0} className="inline-flex rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                    <StatusBadge status={status} label={licenceStatusLabels[status]} tones={licenceStatusTones} className={className} />
                    <span className="sr-only">. {help}</span>
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-64">{help}</TooltipContent>
        </Tooltip>
    );
}
