import { PlanHeaderActions } from '@/components/admin/plans/plan-actions';
import { PlanActivity } from '@/components/admin/plans/plan-activity';
import { formatDate, formatDays, formatMoney } from '@/components/admin/plans/plan-format';
import { PlanStatusBadge, planStatusHelp } from '@/components/admin/plans/plan-status-badge';
import { type FeatureOption, type PlanActivityEntry, type PlanRecord } from '@/components/admin/plans/types';
import { DescriptionList } from '@/components/shared/description-list';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { MoneyIcon } from '@/components/shared/money-icon';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link } from '@inertiajs/react';
import { Archive, CalendarClock, CalendarRange, Check, Clock, Layers, Minus, Pencil } from 'lucide-react';

interface ShowPlanProps {
    plan: PlanRecord;
    features: FeatureOption[];
    activity: PlanActivityEntry[];
}

function yearlyHint(plan: PlanRecord): string {
    if (plan.yearlySavingPercent === null) {
        return `Per ${plan.pricingMode === 'perBranch' ? 'branch' : 'till'}, billed yearly`;
    }
    const saving = Number(plan.yearlySaving);
    if (saving > 0) {
        return `Saves ${formatMoney(plan.yearlySaving)} (${plan.yearlySavingPercent}%) a year`;
    }

    return saving < 0 ? 'More than 12 monthly payments' : 'Same as 12 monthly payments';
}

export default function ShowPlan({ plan, features, activity }: ShowPlanProps) {
    const archived = plan.status === 'archived';
    const included = features.filter((feature) => plan.features.includes(feature.value));
    const excluded = features.filter((feature) => !plan.features.includes(feature.value));

    return (
        <AdminLayout>
            <Head title={plan.name} />

            <PageHeader
                title={plan.name}
                status={<PlanStatusBadge status={plan.status} label={plan.statusLabel} />}
                description={plan.description ?? planStatusHelp[plan.status]}
                back={{ href: route('admin.plans.index'), label: 'Plans' }}
                media={<InitialsAvatar name={plan.name} shape="square" size="lg" icon={Layers} />}
                actions={
                    <>
                        <PlanHeaderActions plan={plan} />
                        {!archived && (
                            <Button asChild>
                                <Link href={route('admin.plans.edit', plan.id)}>
                                    <Pencil />
                                    Edit plan
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            {archived && (
                <Alert variant="warning">
                    <Archive className="size-4" />
                    <AlertDescription>
                        Archived on {formatDate(plan.archivedAt)}. It cannot be edited or chosen for licences until it is restored.
                    </AlertDescription>
                </Alert>
            )}

            <StatGrid>
                {plan.billingType === 'setupOnly' ? (
                    <StatCard
                        label="Setup fee"
                        value={formatMoney(plan.setupFee)}
                        hint="Setup fee only, + VAT, paid by hand. Paid in full = a licence that does not expire."
                        icon={MoneyIcon}
                    />
                ) : (
                    <StatCard
                        label="Monthly price"
                        value={formatMoney(plan.priceMonthly)}
                        hint={
                            plan.billingType === 'setupAndRecurring'
                                ? `Per ${plan.pricingMode === 'perBranch' ? 'branch' : 'till'} by Direct Debit · setup fee ${formatMoney(plan.setupFee)} + VAT`
                                : `Per ${plan.pricingMode === 'perBranch' ? 'branch' : 'till'} by Direct Debit · no setup fee`
                        }
                        icon={MoneyIcon}
                    />
                )}
                <StatCard
                    label={plan.billingType === 'setupOnly' ? 'Plan type' : 'Yearly price'}
                    value={plan.billingType === 'setupOnly' ? 'Setup only' : formatMoney(plan.priceYearly)}
                    hint={plan.billingType === 'setupOnly' ? 'Nothing recurring, no Direct Debit' : yearlyHint(plan)}
                    icon={CalendarRange}
                    tone="success"
                />
                <StatCard
                    label="Free trial"
                    value={formatDays(plan.trialDays, 'No trial')}
                    hint={plan.trialGraceDays > 0 ? `Then ${formatDays(plan.trialGraceDays)} of grace` : 'No grace after the trial'}
                    icon={Clock}
                    tone="neutral"
                />
                <StatCard
                    label="Payment grace"
                    value={formatDays(plan.graceDays)}
                    hint="After a missed renewal, before suspension"
                    icon={CalendarClock}
                    tone="warning"
                />
            </StatGrid>

            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
                <SectionCard
                    className="lg:col-span-2"
                    title="Features"
                    description={
                        <span className="tabular-nums">
                            {included.length} of {features.length} features included.
                        </span>
                    }
                    contentClassName="grid gap-5"
                >
                    {included.length === 0 ? (
                        <p className="text-muted-foreground text-sm">This plan has no extra features. Tills on it get the core till only.</p>
                    ) : (
                        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {included.map((feature) => (
                                <li key={feature.value} className="flex gap-3">
                                    <span className="bg-success-soft text-success-foreground ring-success/20 mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full ring-1">
                                        <Check className="size-3.5" aria-hidden />
                                    </span>
                                    <span className="grid gap-0.5">
                                        <span className="text-sm font-medium">{feature.label}</span>
                                        <span className="text-muted-foreground text-sm">{feature.description}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {excluded.length > 0 && (
                        <div className="grid gap-2 border-t pt-4">
                            <p className="text-muted-foreground text-sm font-medium">Not included</p>
                            <ul className="flex flex-wrap gap-2">
                                {excluded.map((feature) => (
                                    <li
                                        key={feature.value}
                                        className="text-muted-foreground bg-subtle inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs"
                                        title={feature.description}
                                    >
                                        <Minus className="size-3" aria-hidden />
                                        {feature.label}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </SectionCard>

                <SectionCard title="Details">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Code', value: plan.code, mono: true },
                            { label: 'Currency', value: plan.currency === 'GBP' ? 'Pounds sterling (GBP)' : plan.currency },
                            { label: 'New licences', value: plan.isActive ? 'Available' : 'Not available' },
                            { label: 'Pricing page', value: plan.isPublic ? 'Shown' : 'Not shown' },
                            { label: 'Sort order', value: <span className="tabular-nums">{plan.sortOrder}</span> },
                            { label: 'Created', value: formatDate(plan.createdAt) },
                            { label: 'Last changed', value: formatDate(plan.updatedAt) },
                        ]}
                    />
                </SectionCard>
            </div>

            <PlanActivity entries={activity} />
        </AdminLayout>
    );
}
