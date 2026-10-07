import { CheckField } from '@/components/app/setup/fields';
import { PinInput } from '@/components/app/setup/staff-credential-dialogs';
import { StaffSignInCard } from '@/components/app/setup/staff-sign-in-card';
import { type StaffFormData, type StaffFormProps } from '@/components/app/setup/types';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { currencySymbol } from '@/lib/country';
import { hasModule } from '@/lib/country-modules';
import { Head, Link, useForm } from '@inertiajs/react';
import { CircleAlert, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

type Flag = 'is_service_staff' | 'allow_commission' | 'is_personal_licence_holder' | 'big_text_mode';

const FLAGS: { key: Flag; label: string; help: string }[] = [
    { key: 'is_personal_licence_holder', label: 'Personal licence holder', help: 'Can authorise alcohol sales where the law needs one.' },
    { key: 'is_service_staff', label: 'Serves at tables or counters', help: "Shown in the till's service staff list." },
    { key: 'allow_commission', label: 'Earns commission', help: 'Their sales count towards commission reports.' },
    { key: 'big_text_mode', label: 'Large text', help: 'The till uses bigger text when they sign in.' },
];

export default function StaffForm({ member, options, canEdit }: StaffFormProps) {
    const editing = member !== null;
    const { data, setData, post, put, processing, errors, reset } = useForm<StaffFormData>({
        name: member?.name ?? '',
        role_id: member?.role_id ?? '',
        is_active: member?.is_active ?? true,
        rate_per_hour: member?.rate_per_hour ?? '',
        max_shift_hours: member?.max_shift_hours ?? '',
        is_service_staff: member?.is_service_staff ?? false,
        allow_commission: member?.allow_commission ?? false,
        is_personal_licence_holder: member?.is_personal_licence_holder ?? false,
        big_text_mode: member?.big_text_mode ?? false,
        simple_mode_override: member?.simple_mode_override ?? 'role',
        branch_ids: member?.branch_ids ?? [],
        pin: '',
        pin_confirmation: '',
    });
    const title = editing ? member.name : 'Add staff member';
    const invalid = (key: keyof StaffFormData) => (errors[key] ? { 'aria-invalid': true as const } : {});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('app.staff.update', member.id), { preserveScroll: true });
        } else {
            post(route('app.staff.store'), { preserveScroll: true, onFinish: () => reset('pin', 'pin_confirmation') });
        }
    };

    const toggleBranch = (id: string, on: boolean) =>
        setData(
            'branch_ids',
            options.branches.map((b) => b.value).filter((b) => (b === id ? on : data.branch_ids.includes(b))),
        );

    return (
        <AppLayout>
            <Head title={editing ? `Edit ${member.name}` : title} />
            <PageHeader
                title={title}
                status={editing ? <StatusBadge status={member.is_active ? 'active' : 'inactive'} /> : undefined}
                description={editing ? 'Changes reach every till at its next sync.' : 'They can sign in on every till after its next sync.'}
                back={{ href: route('app.staff.index'), label: 'Staff' }}
                media={editing ? <InitialsAvatar name={member.name} size="lg" /> : undefined}
            />

            {options.roles.length === 0 && (
                <Alert variant="warning">
                    <CircleAlert className="size-4" />
                    <AlertDescription>Your tills have not sent their roles yet. Connect a till and let it sync, then add staff.</AlertDescription>
                </Alert>
            )}

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <form onSubmit={submit} className="grid max-w-4xl gap-6" noValidate>
                    <FormCard>
                        <FormSection title="Who they are" description="Their name as it shows on the till's sign-in screen, and what they may do.">
                            <FormGrid>
                                <FormField id="name" label="Name" error={errors.name}>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        maxLength={60}
                                        disabled={!canEdit}
                                        onChange={(e) => setData('name', e.target.value)}
                                        {...invalid('name')}
                                    />
                                </FormField>
                                <FormField
                                    id="role_id"
                                    label="Till role"
                                    error={errors.role_id}
                                    help={
                                        <Link href={route('app.staff.roles')} className="underline">
                                            What each role may do
                                        </Link>
                                    }
                                >
                                    <Select value={data.role_id} onValueChange={(v) => setData('role_id', v)} disabled={!canEdit}>
                                        <SelectTrigger id="role_id" {...invalid('role_id')}>
                                            <SelectValue placeholder="Choose a role" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.roles.map((r) => (
                                                <SelectItem key={r.value} value={r.value}>
                                                    {r.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </FormField>
                            </FormGrid>
                            <CheckField
                                id="is_active"
                                label="Active"
                                help="Inactive staff cannot sign in. Their past sales keep their name."
                                checked={data.is_active}
                                onChange={(v) => setData('is_active', v)}
                                disabled={!canEdit}
                            />
                        </FormSection>

                        {!editing && (
                            <FormSection
                                title="PIN"
                                description="4 to 8 digits that no colleague uses. Only a scrambled copy is kept; tell them in person."
                            >
                                <FormGrid>
                                    <FormField id="pin" label="PIN" error={errors.pin}>
                                        <PinInput id="pin" value={data.pin} onChange={(v) => setData('pin', v)} invalid={Boolean(errors.pin)} />
                                    </FormField>
                                    <FormField id="pin_confirmation" label="Type it again">
                                        <PinInput
                                            id="pin_confirmation"
                                            value={data.pin_confirmation}
                                            onChange={(v) => setData('pin_confirmation', v)}
                                        />
                                    </FormField>
                                </FormGrid>
                            </FormSection>
                        )}

                        {options.branches.length > 0 && (
                            <FormSection
                                title="Shops"
                                description="Where they usually work, for your staff list. Every till still lets them sign in."
                            >
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {options.branches.map((b) => (
                                        <CheckField
                                            key={b.value}
                                            id={`branch-${b.value}`}
                                            label={b.label}
                                            checked={data.branch_ids.includes(b.value)}
                                            onChange={(on) => toggleBranch(b.value, on)}
                                            disabled={!canEdit}
                                        />
                                    ))}
                                </div>
                                {errors.branch_ids && <p className="text-destructive text-sm">{errors.branch_ids}</p>}
                            </FormSection>
                        )}

                        <FormSection title="Pay and hours" description="Used by the till's timesheets and wage reports.">
                            <FormGrid>
                                <FormField
                                    id="rate_per_hour"
                                    label={`Hourly rate (${currencySymbol()})`}
                                    optional
                                    help="Used for the wage estimate on Timesheets."
                                    error={errors.rate_per_hour}
                                >
                                    <Input
                                        id="rate_per_hour"
                                        inputMode="decimal"
                                        value={data.rate_per_hour}
                                        disabled={!canEdit}
                                        onChange={(e) => setData('rate_per_hour', e.target.value)}
                                        {...invalid('rate_per_hour')}
                                    />
                                </FormField>
                                <FormField
                                    id="max_shift_hours"
                                    label="Longest shift (hours)"
                                    optional
                                    help="The till warns when a shift runs longer. 0 for no warning."
                                    error={errors.max_shift_hours}
                                >
                                    <Input
                                        id="max_shift_hours"
                                        inputMode="decimal"
                                        value={data.max_shift_hours}
                                        disabled={!canEdit}
                                        onChange={(e) => setData('max_shift_hours', e.target.value)}
                                        {...invalid('max_shift_hours')}
                                    />
                                </FormField>
                            </FormGrid>
                        </FormSection>

                        <FormSection title="On the till" description="How the till treats them once they sign in.">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {FLAGS.filter((f) => f.key !== 'is_personal_licence_holder' || hasModule('alcoholLicensing')).map((f) => (
                                    <CheckField
                                        key={f.key}
                                        id={f.key}
                                        label={f.label}
                                        help={f.help}
                                        checked={data[f.key]}
                                        onChange={(v) => setData(f.key, v)}
                                        disabled={!canEdit}
                                    />
                                ))}
                            </div>
                            <FormField
                                id="simple_mode_override"
                                label="Simple mode"
                                help="A cut-down till screen for new or occasional staff."
                                className="sm:max-w-xs"
                            >
                                <Select
                                    value={data.simple_mode_override}
                                    onValueChange={(v) => setData('simple_mode_override', v as StaffFormData['simple_mode_override'])}
                                    disabled={!canEdit}
                                >
                                    <SelectTrigger id="simple_mode_override">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="role">As their role says</SelectItem>
                                        <SelectItem value="on">Always on</SelectItem>
                                        <SelectItem value="off">Always off</SelectItem>
                                    </SelectContent>
                                </Select>
                            </FormField>
                        </FormSection>

                        <div className="bg-subtle flex flex-col-reverse gap-2 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                            <Button variant="outline" asChild>
                                <Link href={route('app.staff.index')}>{canEdit ? 'Cancel' : 'Back'}</Link>
                            </Button>
                            {canEdit && (
                                <Button type="submit" disabled={processing || options.roles.length === 0}>
                                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                                    {editing ? 'Save changes' : 'Add staff member'}
                                </Button>
                            )}
                        </div>
                    </FormCard>
                </form>

                {editing && <StaffSignInCard member={member} canEdit={canEdit} />}
            </div>
        </AppLayout>
    );
}
