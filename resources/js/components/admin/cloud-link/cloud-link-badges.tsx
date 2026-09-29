import { type CloudMove, type LocalKeyRecord } from '@/components/admin/cloud-link/types';
import { Badge } from '@/components/ui/badge';
import { CircleCheck, CloudUpload, Copy, TriangleAlert } from 'lucide-react';

const number = new Intl.NumberFormat('en-GB');

export function MoveStatusBadge({ move }: { move: CloudMove }) {
    return move.status === 'complete' ? (
        <Badge variant="success">
            <CircleCheck aria-hidden />
            Complete
        </Badge>
    ) : (
        <Badge variant="info">
            <CloudUpload aria-hidden />
            Uploading
        </Badge>
    );
}

/** Rows received of the rows the till said it would send, as a bar and figures. */
export function UploadProgress({ move }: { move: CloudMove }) {
    return (
        <div className="grid min-w-40 gap-1">
            <div
                className="bg-muted h-1.5 overflow-hidden rounded-full"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={move.percent}
                aria-label={`${move.percent}% of the history uploaded`}
            >
                <div className={move.status === 'complete' ? 'bg-success h-full' : 'bg-primary h-full'} style={{ width: `${move.percent}%` }} />
            </div>
            <div className="text-muted-foreground text-xs tabular-nums">
                {number.format(move.receivedRows)} of {number.format(move.expectedRows)} rows · {move.percent}%
            </div>
        </div>
    );
}

export function LocalKeyBadges({ record }: { record: LocalKeyRecord }) {
    return (
        <div className="flex flex-wrap gap-1">
            {record.expired ? <Badge variant="neutral">Expired</Badge> : <Badge variant="success">Valid</Badge>}
            {record.kind === 'trial' && <Badge variant="violet">Trial</Badge>}
            {record.refusedCount > 0 && (
                <Badge variant="danger" title="The same key was reported from another PC and refused">
                    <Copy aria-hidden />
                    {record.refusedCount} other {record.refusedCount === 1 ? 'PC' : 'PCs'}
                </Badge>
            )}
            {record.installsForShop !== null && record.maxRegisters !== null && record.installsForShop > record.maxRegisters && (
                <Badge variant="warning" title="More local tills reported for this shop than the key allows (shown, not refused)">
                    <TriangleAlert aria-hidden />
                    {record.installsForShop} of {record.maxRegisters} tills
                </Badge>
            )}
        </div>
    );
}

export { number as cloudNumber };
