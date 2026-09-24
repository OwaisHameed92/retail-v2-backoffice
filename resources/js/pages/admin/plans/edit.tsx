import { PlanForm, planFormDefaults, type PlanFormData } from '@/components/admin/plans/plan-form';
import { PlanStatusBadge } from '@/components/admin/plans/plan-status-badge';
import { type FeatureOption, type PlanRecord } from '@/components/admin/plans/types';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface EditPlanProps {
    plan: PlanRecord;
    features: FeatureOption[];
}

export default function EditPlan({ plan, features }: EditPlanProps) {
    const { data, setData, put, processing, errors } = useForm<PlanFormData>(planFormDefaults(plan));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.plans.update', plan.id), { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title={`Edit ${plan.name}`} />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <PageHeader
                    title={`Edit ${plan.name}`}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <PlanStatusBadge status={plan.status} label={plan.statusLabel} />
                            <span>Every change is recorded in the plan's activity.</span>
                        </span>
                    }
                    actions={
                        <Button variant="ghost" asChild>
                            <Link href={route('admin.plans.show', plan.id)}>
                                <ArrowLeft />
                                Back to plan
                            </Link>
                        </Button>
                    }
                />
                <PlanForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    features={features}
                    onSubmit={submit}
                    submitLabel="Save changes"
                    cancelHref={route('admin.plans.show', plan.id)}
                />
            </div>
        </AdminLayout>
    );
}
