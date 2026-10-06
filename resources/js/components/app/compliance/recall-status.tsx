import { StatusBadge } from '@/components/shared/status-badge';

/**
 * A recall's status, read only: each shop closes and reopens it at its till (till 0.1.52, ProductRecallBranchState).
 * With several shops counted, a recall some have closed reads "Open in 2 of 3 shops".
 */
export function RecallStatus({ status, openShops, shops }: { status: 'open' | 'closed'; openShops?: number; shops?: number }) {
    const several = shops !== undefined && shops > 1;
    const label =
        status === 'closed'
            ? several
                ? 'Closed in every shop'
                : 'Closed'
            : several && openShops !== undefined && openShops < shops
              ? `Open in ${openShops} of ${shops} shops`
              : 'Open';

    return <StatusBadge status={status} label={label} tones={{ open: 'danger', closed: 'neutral' }} />;
}
