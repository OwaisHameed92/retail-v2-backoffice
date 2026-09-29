import { cloudNumber } from '@/components/admin/cloud-link/cloud-link-badges';
import { localKeyColumns, moveColumns } from '@/components/admin/cloud-link/cloud-link-columns';
import { ClearLocalKeyDialog } from '@/components/admin/cloud-link/clear-local-key-dialog';
import { type CloudLinkSummary, type CloudMove, type LocalKeyRecord } from '@/components/admin/cloud-link/types';
import { DataTable, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import AdminLayout from '@/layouts/admin-layout';
import { Head, router } from '@inertiajs/react';
import { CircleCheck, CloudUpload, Copy, KeyRound } from 'lucide-react';
import { useMemo, useState } from 'react';

type View = 'moves' | 'keys' | 'refused';

interface CloudLinkIndexProps {
    view: View;
    moves: Paginated<CloudMove> | null;
    keys: Paginated<LocalKeyRecord> | null;
    summary: CloudLinkSummary;
    canClear: boolean;
}

/** Module 2.8: shops moving to the cloud (cloud/migrate) and the local key register (licence/redeem reports). */
export default function CloudLinkIndex({ view, moves, keys, summary, canClear }: CloudLinkIndexProps) {
    const [clearing, setClearing] = useState<LocalKeyRecord | null>(null);
    const moveCols = useMemo(() => moveColumns(), []);
    const keyCols = useMemo(() => localKeyColumns(canClear ? setClearing : undefined), [canClear]);
    const href = (next: View) => route('admin.cloud-link.index', next === 'moves' ? {} : { view: next });

    return (
        <AdminLayout>
            <Head title="Cloud link" />

            <PageHeader
                title="Cloud link"
                description="Shops moving their history to the cloud, and the dealer (local) keys tills have reported."
                tabs={
                    <PageTabs
                        label="Cloud link views"
                        tabs={[
                            { label: 'Moves to the cloud', href: href('moves'), active: view === 'moves', count: summary.uploading + summary.complete },
                            { label: 'Local keys', href: href('keys'), active: view === 'keys', count: summary.localKeys },
                            { label: 'Used on two PCs', href: href('refused'), active: view === 'refused', count: summary.refused },
                        ]}
                    />
                }
            />

            <StatGrid>
                <StatCard label="Uploading now" value={<span className="tabular-nums">{cloudNumber.format(summary.uploading)}</span>} icon={CloudUpload} hint="History still arriving" />
                <StatCard
                    label="Moved to the cloud"
                    value={<span className="tabular-nums">{cloudNumber.format(summary.complete)}</span>}
                    icon={CircleCheck}
                    tone="success"
                    hint="Every row counted and received"
                />
                <StatCard
                    label="Local keys"
                    value={<span className="tabular-nums">{cloudNumber.format(summary.localKeys)}</span>}
                    icon={KeyRound}
                    tone="neutral"
                    hint="Dealer keys reported by tills"
                    href={href('keys')}
                />
                <StatCard
                    label="Used on two PCs"
                    value={<span className="tabular-nums">{cloudNumber.format(summary.refused)}</span>}
                    icon={Copy}
                    tone={summary.refused > 0 ? 'danger' : 'neutral'}
                    hint="A second PC was refused and locked"
                    href={href('refused')}
                />
            </StatGrid>

            {moves && (
                <DataTable
                    columns={moveCols}
                    data={moves.data}
                    meta={moves.meta}
                    only={['moves', 'summary']}
                    searchPlaceholder="Search business, shop, PC or install code"
                    getRowId={(row) => row.id}
                    onRowClick={(row) => router.visit(route('admin.tenants.show', { company: row.company.id, tab: 'cloud' }))}
                    empty={
                        <EmptyState
                            icon={CloudUpload}
                            title="No shop has moved to the cloud yet"
                            body="When a shop presses Connect with its sync key, its main till uploads its history here. Progress shows as the rows arrive."
                        />
                    }
                />
            )}

            {keys && (
                <DataTable
                    columns={keyCols}
                    data={keys.data}
                    meta={keys.meta}
                    only={['keys', 'summary']}
                    searchPlaceholder="Search install code, licence id, shop, PC or token hash"
                    getRowId={(row) => row.id}
                    empty={
                        <EmptyState
                            icon={KeyRound}
                            title={view === 'refused' ? 'No key has been used on two PCs' : 'No local key reported yet'}
                            body="A till with a dealer key reports it once when it can reach the portal. The first PC is recorded; the same key from another PC is refused."
                        />
                    }
                />
            )}

            <SectionCard title="How this works" description="Contract v1.4.1 §17.6, §17.8 and §17.16.">
                <ul className="text-muted-foreground grid gap-2 text-sm">
                    <li>A move starts when the main till connects with the shop’s sync key. The till keeps its own ids; we adopt or alias them.</li>
                    <li>The history arrives in batches while the shop keeps trading. It is complete when every row the till counted has arrived.</li>
                    <li>A dealer key is bound to the first PC that reports it. Clear a record only when the dealer moved the key to a new PC.</li>
                </ul>
            </SectionCard>

            <ClearLocalKeyDialog record={clearing} onOpenChange={(open) => !open && setClearing(null)} />
        </AdminLayout>
    );
}
