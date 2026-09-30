import { StatusPill } from '@/components/shared/status-badge';
import { type JournalRow } from './types';

/** Reversed / reversal / "posted before the refund fix" pills of a journal entry; nothing when none applies. */
export function JournalFlags({ entry }: { entry: Pick<JournalRow, 'isReversed' | 'isReversal' | 'oldRefund'> }) {
    if (!entry.isReversed && !entry.isReversal && !entry.oldRefund) {
        return null;
    }

    return (
        <span className="flex flex-wrap gap-1.5">
            {entry.oldRefund && <StatusPill tone="warning">Posted before the refund fix</StatusPill>}
            {entry.isReversed && <StatusPill tone="neutral">Reversed</StatusPill>}
            {entry.isReversal && <StatusPill tone="info">Reversal</StatusPill>}
        </span>
    );
}
