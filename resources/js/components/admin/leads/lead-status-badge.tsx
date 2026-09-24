import { StatusBadge } from '@/components/shared/status-badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { leadStatusHelp, leadStatusLabels, leadStatusTones } from './format';
import { type LeadStatus } from './types';

/** Lead status pill with the lead tones, and a tooltip saying what the status means. */
export function LeadStatusBadge({ status, withHelp = false }: { status: LeadStatus; withHelp?: boolean }) {
    const badge = <StatusBadge status={status} tones={leadStatusTones} label={leadStatusLabels[status]} />;

    if (!withHelp) {
        return badge;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span tabIndex={0} className="focus-visible:ring-ring/40 rounded-full outline-none focus-visible:ring-2">
                    {badge}
                    <span className="sr-only">. {leadStatusHelp[status]}</span>
                </span>
            </TooltipTrigger>
            <TooltipContent>{leadStatusHelp[status]}</TooltipContent>
        </Tooltip>
    );
}
