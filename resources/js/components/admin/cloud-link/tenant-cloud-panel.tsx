import { LocalKeyBadges, MoveStatusBadge, UploadProgress } from '@/components/admin/cloud-link/cloud-link-badges';
import { ClearLocalKeyDialog } from '@/components/admin/cloud-link/clear-local-key-dialog';
import { type CloudMove, type LocalKeyRecord, reportedViaLabels } from '@/components/admin/cloud-link/types';
import { formatDateTime } from '@/components/admin/format';
import { formatDate } from '@/components/admin/tenants/format';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { Button } from '@/components/ui/button';
import { CloudUpload, KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface TenantCloudPanelProps {
    moves: CloudMove[];
    keys: LocalKeyRecord[];
    canClear: boolean;
}

/** Module 2.8, the tenant page's "Cloud link" tab: this business's moves to the cloud and its reported dealer keys. */
export function TenantCloudPanel({ moves, keys, canClear }: TenantCloudPanelProps) {
    const [clearing, setClearing] = useState<LocalKeyRecord | null>(null);

    return (
        <>
            <SectionCard title="Moves to the cloud" description="Each shop whose main till uploaded its history with its sync key." flush={moves.length > 0}>
                {moves.length === 0 ? (
                    <EmptyState icon={CloudUpload} title="No shop has moved yet" body="Shops that started on the portal need no move: their tills sync from the start." />
                ) : (
                    <ul className="divide-y">
                        {moves.map((move) => (
                            <li key={move.id} className="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center sm:px-6">
                                <div className="grid gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{move.branch.name ?? 'Unknown shop'}</span>
                                        <MoveStatusBadge move={move} />
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        {move.deviceName ?? 'Unknown PC'} · started {formatDateTime(move.startedAt)}
                                        {move.completedAt ? ` · completed ${formatDateTime(move.completedAt)}` : ''}
                                        {move.carriedOverDays > 0 ? ` · ${move.carriedOverDays} licence days carried over` : ''}
                                    </p>
                                    {move.status === 'open' && move.missing.length > 0 && (
                                        <p className="text-warning-foreground text-sm">
                                            Still missing: {move.missing.map((m) => `${m.entity} ${m.received}/${m.expected}`).join(', ')}
                                        </p>
                                    )}
                                </div>
                                <UploadProgress move={move} />
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>

            <SectionCard title="Local (dealer) keys" description="Keys from the licence generator that this business’s tills reported." flush={keys.length > 0}>
                {keys.length === 0 ? (
                    <EmptyState icon={KeyRound} title="No local key reported" body="Tills on portal licences never report one." />
                ) : (
                    <ul className="divide-y">
                        {keys.map((key) => (
                            <li key={key.id} className="grid grid-cols-1 gap-2 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center sm:px-6">
                                <div className="grid gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-mono text-sm">{key.installCode}</span>
                                        <LocalKeyBadges record={key} />
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        {key.deviceName ?? 'Unknown PC'} · {key.branchName ?? key.businessName ?? '—'} · ends {formatDate(key.expiresAt)} ·{' '}
                                        {reportedViaLabels[key.reportedVia].toLowerCase()} {formatDateTime(key.firstSeenAt)}
                                    </p>
                                    <p className="text-muted-foreground font-mono text-xs">{key.licenceId}</p>
                                </div>
                                {canClear && (
                                    <Button variant="outline" size="sm" onClick={() => setClearing(key)}>
                                        <Trash2 />
                                        Clear record
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>

            <ClearLocalKeyDialog record={clearing} onOpenChange={(open) => !open && setClearing(null)} />
        </>
    );
}
