import { PlanHeaderActions } from '@/components/admin/plans/plan-actions';
import { PlanActivity } from '@/components/admin/plans/plan-activity';
import { formatDate, formatDays, formatMoney } from '@/components/admin/plans/plan-format';
import { PlanStatusBadge, planStatusHelp } from '@/components/admin/plans/plan-status-badge';
import { type FeatureOption, type PlanActivityEntry, type PlanRecord } from '@/components/admin/plans/types';
import { StatCard } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link } from '@inertiajs/react';
import { Archive, ArrowLeft, CalendarClock, CalendarRange, Check, Clock, Minus, Pencil, PoundSterling } from 'lucide-react';
import { type ReactNode } from 'react';

interface ShowPlanProps {
    plan: PlanRecord;
    features: FeatureOption[];
    activity: PlanActivityEntry[];
}

function yearlyHint(plan: PlanRecord): string {
    if (plan.yearlySavingPercent === null) {
        return 'Per till, billed yearly';
    }
    const saving = Number(plan.yearlySaving);
    if (saving > 0) {
        return `Saves ${formatMoney(plan.yearlySaving)} (${plan.yearlySavingPercent}%) a year`;
    }

    return saving < 0 ? 'More than 12 monthly payments' : 'Same as 12 monthly payments';
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[8.5rem_minmax(0,1fr)] gap-3 py-2.5 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </div>
    );
}

export default function ShowPlan({ plan, features, activity }: ShowPlanProps) {
    const archived = plan.status === 'archived';
    const included = features.filter((feature) => plan.features.includes(feature.value));
    const excluded = features.filter((feature) => !plan.features.includes(feature.value));

    return (
        <AdminLayout>
            <Head title={plan.name} />

            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-1">
                    <Link
                        href={route('admin.plans.index')}
                        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        Plans
                    </Link>
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="truncate text-xl font-semibold tracking-tight">{plan.name}</h1>
                        <PlanStatusBadge status={plan.status} label={plan.statusLabel} />
                    </div>
                    <p className="text-muted-foreground text-sm">{plan.description ?? planStatusHelp[plan.status]}</p>
                </div>
                <div className="flex flex-wrap items-center gap-2 sm:shrink-0">
                    {!archived && (
                        <Button asChild>
                            <Link href={route('admin.plans.edit', plan.id)}>
                                <Pencil />
                                Edit plan
                            </Link>
                        </Button>
                    )}
                    <PlanHeaderActions plan={plan} />
                </div>
            </div>

            {archived && (
                <Alert className="border-warning/40 bg-warning-soft">
                    <Archive className="size-4" />
                    <AlertDescription className="text-warning-foreground">
                        Archived on {formatDate(plan.archivedAt)}. It cannot be edited or chosen for licences until it is restored.
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Monthly price" value={formatMoney(plan.pricePerTillMonthly)} hint="Per till, billed monthly" icon={PoundSterling} />
                <StatCard label="Yearly price" value={formatMoney(plan.pricePerTillYearly)} hint={yearlyHint(plan)} icon={CalendarRange} />
                <StatCard
                    label="Free trial"
                    value={formatDays(plan.trialDays, 'No trial')}
                    hint={plan.trialGraceDays > 0 ? `Then ${formatDays(plan.trialGraceDays)} of grace` : 'No grace after the trial'}
                    icon={Clock}
                />
                <StatCard
                    label="Payment grace"
                    value={formatDays(plan.graceDays)}
                    hint="After a missed renewal, before suspension"
                    icon={CalendarClock}
                />
            </div>

            <div className="grid items-start gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader className="pb-4">
                        <h2 className="text-base font-semibold">Features</h2>
                        <p className="text-muted-foreground text-sm tabular-nums">
                            {included.length} of {features.length} features included.
                        </p>
                    </CardHeader>
                    <CardContent className="grid gap-5">
                        {included.length === 0 ? (
                            <p className="text-muted-foreground text-sm">This plan has no extra features. Tills on it get the core till only.</p>
                        ) : (
                            <ul className="grid gap-3 sm:grid-cols-2">
                                {included.map((feature) => (
                                    <li key={feature.value} className="flex gap-3">
                                        <span className="bg-success-soft text-success-foreground mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full">
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
                                            className="text-muted-foreground inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs"
                                            title={feature.description}
                                        >
                                            <Minus className="size-3" aria-hidden />
                                            {feature.label}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <h2 className="text-base font-semibold">Details</h2>
                    </CardHeader>
                    <CardContent>
                        <dl className="divide-y">
                            <DetailRow label="Code">
                                <span className="font-mono">{plan.code}</span>
                            </DetailRow>
                            <DetailRow label="Currency">{plan.currency === 'GBP' ? 'Pounds sterling (GBP)' : plan.currency}</DetailRow>
                            <DetailRow label="New licences">{plan.isActive ? 'Available' : 'Not available'}</DetailRow>
                            <DetailRow label="Pricing page">{plan.isPublic ? 'Shown' : 'Not shown'}</DetailRow>
                            <DetailRow label="Sort order">
                                <span className="tabular-nums">{plan.sortOrder}</span>
                            </DetailRow>
                            <DetailRow label="Created">{formatDate(plan.createdAt)}</DetailRow>
                            <DetailRow label="Last changed">{formatDate(plan.updatedAt)}</DetailRow>
                        </dl>
                    </CardContent>
                </Card>
            </div>

            <PlanActivity entries={activity} />
        </AdminLayout>
    );
}
