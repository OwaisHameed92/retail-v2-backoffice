import { ComplianceFilters, CompliancePageLayout } from '@/components/app/compliance/compliance-page';
import { type OverviewProps } from '@/components/app/compliance/types';
import { AttentionList } from '@/components/shared/attention-list';
import { useTableQuery } from '@/components/shared/data-table';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { formatNumber } from '@/lib/country';
import { BadgeCheck, CalendarCheck, FileWarning, GraduationCap, OctagonAlert, PackageX, ShieldAlert } from 'lucide-react';

/** Compliance overview (module 5.7): headline figures and what needs doing (expiry reminders, missed checks, recalls). */
export default function ComplianceOverview({ attention, figures, windows, filters, options }: OverviewProps) {
    const { update } = useTableQuery();
    const shop = filters.shopLocked ? {} : { shop: filters.shop ?? 'all' };
    const week = { ...shop, ...windows.week };
    const month = { ...shop, ...windows.month };

    return (
        <CompliancePageLayout
            tab="overview"
            filters={filters}
            title="Overview"
            description="Age checks, incidents, training, diary checks, licences and recalls across your shops."
        >
            <ComplianceFilters filters={filters} options={options} update={update} dates={false} staff={false} />

            <StatGrid columns={3}>
                <StatCard
                    label="Age refusals"
                    value={formatNumber(figures.refusals)}
                    hint={figures.refusalRate !== null ? `${figures.refusalRate}% of age checks · last 7 days` : 'Last 7 days'}
                    icon={ShieldAlert}
                    tone="neutral"
                    href={route('app.compliance.age-checks', week)}
                />
                <StatCard
                    label="Missed diary checks"
                    value={formatNumber(figures.missedChecks)}
                    hint="Last 7 days"
                    icon={CalendarCheck}
                    tone={figures.missedChecks > 0 ? 'warning' : 'success'}
                    href={route('app.compliance.diary', week)}
                />
                <StatCard
                    label="Incidents"
                    value={formatNumber(figures.incidents)}
                    hint="Last 30 days"
                    icon={FileWarning}
                    tone="neutral"
                    href={route('app.compliance.incidents', month)}
                />
                <StatCard
                    label="Licences to renew"
                    value={formatNumber(figures.licencesDue)}
                    hint="Expired or due within 60 days"
                    icon={BadgeCheck}
                    tone={figures.licencesDue > 0 ? 'danger' : 'success'}
                    href={route('app.compliance.licences', shop)}
                />
                <StatCard
                    label="Training to refresh"
                    value={formatNumber(figures.trainingDue)}
                    hint="Expired or due within 30 days"
                    icon={GraduationCap}
                    tone={figures.trainingDue > 0 ? 'warning' : 'success'}
                    href={route('app.compliance.training', shop)}
                />
                <StatCard
                    label="Open recalls"
                    value={formatNumber(figures.openRecalls)}
                    hint="Sent to every till"
                    icon={PackageX}
                    tone={figures.openRecalls > 0 ? 'danger' : 'success'}
                    href={route('app.compliance.recalls', { status: 'open' })}
                />
            </StatGrid>

            <AttentionList
                title="Needs attention"
                icon={OctagonAlert}
                items={attention.items}
                total={attention.total}
                emptyTitle="All in order"
                emptyBody="No licences or training running out, no missed diary checks this week and no open recalls."
            />
        </CompliancePageLayout>
    );
}
