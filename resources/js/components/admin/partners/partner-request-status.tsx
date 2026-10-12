import { type PartnerRequestStatus } from '@/components/admin/partners/types';
import { StatusBadge, type StatusToneMap } from '@/components/shared/status-badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { TriangleAlert } from 'lucide-react';

export const partnerRequestLabels: Record<PartnerRequestStatus, string> = {
    pendingReview: 'Pending review',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

export const partnerRequestTones: StatusToneMap = {
    pendingReview: 'warning',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

const help: Record<PartnerRequestStatus, string> = {
    pendingReview: 'Waiting for a review. Nothing is created until someone approves it.',
    approved: 'Approved: the business exists and the partner was told.',
    rejected: 'Rejected: the partner sees the reason.',
    cancelled: 'Cancelled by the partner before review, or the business was cancelled.',
};

/** Partner request status pill; with `withHelp`, a tooltip saying what it means. */
export function PartnerRequestStatusBadge({ status, withHelp = false }: { status: PartnerRequestStatus; withHelp?: boolean }) {
    const badge = <StatusBadge status={status} tones={partnerRequestTones} label={partnerRequestLabels[status]} />;

    if (!withHelp) {
        return badge;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span tabIndex={0} className="focus-visible:ring-ring/40 rounded-full outline-none focus-visible:ring-2">
                    {badge}
                    <span className="sr-only">. {help[status]}</span>
                </span>
            </TooltipTrigger>
            <TooltipContent>{help[status]}</TooltipContent>
        </Tooltip>
    );
}

/** The amber "Partner cancelled" pill on approved requests the partner called off. */
export function PartnerCancelledPill() {
    return (
        <span className="bg-warning-soft text-warning-foreground ring-warning/30 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1">
            <TriangleAlert className="size-3" aria-hidden />
            Partner cancelled
        </span>
    );
}
