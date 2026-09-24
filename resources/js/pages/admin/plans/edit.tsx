import { PlanForm, planFormDefaults, type PlanFormData } from '@/components/admin/plans/plan-form';
import { PlanStatusBadge } from '@/components/admin/plans/plan-status-badge';
import { type FeatureOption, type PlanRecord } from '@/components/admin/plans/types';
import { PageHeader } from '@/components/shared/page-header';
import AdminLayout from '@/layouts/admin-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface EditPlanProps {
    plan: PlanRecord;
    features: FeatureOption[];
}

export default function EditPlan({ plan, features }: EditPlanProps) {
    const { data, setData, put, processing, errors, isDirty } = useForm<PlanFormData>(planFormDefaults(plan));

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
                    status={<PlanStatusBadge status={plan.status} label={plan.statusLabel} />}
                    description="Every change is recorded in the plan's activity."
                    back={{ href: route('admin.plans.show', plan.id), label: plan.name }}
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
                    isDirty={isDirty}
                />
            </div>
        </AdminLayout>
    );
}
