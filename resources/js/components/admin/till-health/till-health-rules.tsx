import { type HealthThresholds } from '@/components/till-health/types';
import { DescriptionList } from '@/components/shared/description-list';
import { SectionCard } from '@/components/shared/section-card';
import { timeZoneLabel } from '@/lib/country';

/** "How health is worked out": the thresholds in config/till-health.php, so staff can read a state. */
export function TillHealthRules({ thresholds: t }: { thresholds: HealthThresholds }) {
    return (
        <SectionCard
            title="How health is worked out"
            description="Set in config/till-health.php. The main till syncs the shop; other tills only check their licence once a day."
        >
            <DescriptionList
                columns={3}
                items={[
                    { label: 'Online', value: `Main till synced within ${t.syncOnlineMinutes} min; other tills checked in within ${t.validateOnlineHours} h` },
                    {
                        label: 'Offline',
                        value: `Main till: no sync for ${t.syncOfflineHours} h and no check-in for ${t.validateOnlineHours} h. Other tills: no check-in for ${t.validateOfflineHours} h`,
                    },
                    { label: 'Stale', value: 'Heard from, but neither online nor offline' },
                    { label: 'Old version', value: `Below SSPOS ${t.minimumAppVersion} (the licence API minimum)` },
                    { label: 'Clock skew', value: `Till clock more than ${t.clockSkewSeconds} s off at its last check-in` },
                    {
                        label: 'Sync failing / stalled',
                        value: `An error since the last good call within ${t.syncFailingHours} h / no sync for ${t.syncStalledHours} h while the till is on, or a growing queue`,
                    },
                    {
                        label: 'Alerts',
                        value: `Raised on the till’s licence; "Till offline" after ${t.alertOfflineHours} silent trading hours (${t.tradingStart}–${t.tradingEnd} ${timeZoneLabel()}). Each clears itself when fixed.`,
                        wide: true,
                    },
                ]}
            />
        </SectionCard>
    );
}
