import { DescriptionList } from '@/components/shared/description-list';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { ago } from '@/components/till-health/format';
import { formatDate, formatDateTime, formatNumber } from '@/lib/country';

type DmdFetchOutcome = 'published' | 'unchanged' | 'failed';

export interface DmdServerStatus {
    enabled: boolean;
    keyConfigured: boolean;
    release: { name: string; week: string; releaseDate: string; sizeBytes: number; sha256: string; publishedAt: string | null } | null;
    lastRun: { outcome: DmdFetchOutcome; at: string | null } | null;
    lastError: { message: string; at: string | null } | null;
}

const outcomeText: Record<DmdFetchOutcome, string> = {
    published: 'Published a new week',
    unchanged: 'Nothing new on TRUD',
    failed: 'Failed',
};

function size(bytes: number): string {
    return bytes >= 1048576 ? `${formatNumber(Math.round((bytes / 1048576) * 10) / 10)} MB` : `${formatNumber(Math.max(1, Math.round(bytes / 1024)))} KB`;
}

/**
 * "dm+d release server" (W4): read only. The weekly NHS medicines dictionary served to pharmacy tills, from NHS TRUD
 * with our key. Shows whether a key is set, never the key.
 */
export function DmdServerCard({ status }: { status: DmdServerStatus }) {
    const { release, lastRun, lastError } = status;

    return (
        <SectionCard
            title="dm+d release server"
            description="The weekly NHS medicines dictionary for pharmacy tills, fetched from NHS TRUD every Monday (again on Tuesday for a bank holiday)."
            actions={
                <StatusPill tone={status.enabled ? 'success' : 'neutral'}>{status.enabled ? 'On' : 'Off (PHARMACY_DMD)'}</StatusPill>
            }
        >
            <DescriptionList
                columns={3}
                items={[
                    { label: 'TRUD API key', value: status.keyConfigured ? 'Set' : 'Not set' },
                    { label: 'Serving', value: release ? `${release.name}, week ${release.week}` : 'No release yet' },
                    { label: 'NHSBSA release date', value: release ? formatDate(release.releaseDate) : null },
                    { label: 'Size', value: release ? size(release.sizeBytes) : null },
                    { label: 'SHA-256', value: release ? `${release.sha256}…` : null, mono: true },
                    { label: 'Published', value: release?.publishedAt ? formatDateTime(release.publishedAt) : null },
                    { label: 'Last run', value: lastRun ? `${outcomeText[lastRun.outcome]}, ${ago(lastRun.at)}` : 'Never' },
                    {
                        label: 'Last error',
                        value: lastError ? `${formatDateTime(lastError.at)}: ${lastError.message}` : 'None',
                        wide: true,
                    },
                ]}
            />
        </SectionCard>
    );
}
