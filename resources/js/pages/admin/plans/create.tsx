import { PlanForm, planFormDefaults, type PlanFormData } from '@/components/admin/plans/plan-form';
import { type FeatureOption } from '@/components/admin/plans/types';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface CreatePlanProps {
    features: FeatureOption[];
    defaults: { trialDays: number; trialGraceDays: number; graceDays: number; sortOrder: number };
}

export default function CreatePlan({ features, defaults }: CreatePlanProps) {
    const { data, setData, post, processing, errors } = useForm<PlanFormData>(planFormDefaults(undefined, defaults));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.plans.store'), { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title="Add plan" />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <PageHeader
                    title="Add plan"
                    description="Set the price per till, trial and the features this plan switches on."
                    actions={
                        <Button variant="ghost" asChild>
                            <Link href={route('admin.plans.index')}>
                                <ArrowLeft />
                                All plans
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
                    submitLabel="Create plan"
                    cancelHref={route('admin.plans.index')}
                    autoCode
                />
            </div>
        </AdminLayout>
    );
}
