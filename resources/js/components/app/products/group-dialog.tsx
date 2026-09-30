import { FormField, FormGrid } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';
import { CheckRow, OptionSelect } from './fields';
import { type CatalogueOptions, type CategoryNode, type DepartmentNode } from './types';

export type GroupTarget =
    | { kind: 'department'; department: DepartmentNode | null }
    | { kind: 'category'; category: CategoryNode | null; departmentId: string; parentId: string | null };

type GroupValues = {
    name: string;
    colour_hex: string;
    is_active: boolean;
    is_visible_on_till: boolean;
    show_in_report: boolean;
    default_vat_rate_id: string;
    department_id: string;
    parent_category_id: string;
    age_rule_default: string;
    negative_stock_mode: string;
};

function initial(target: GroupTarget): GroupValues {
    const d = target.kind === 'department' ? target.department : null;
    const c = target.kind === 'category' ? target.category : null;
    const source = d ?? c;

    return {
        name: source?.name ?? '',
        colour_hex: source?.colourHex ?? '#1F6FEB',
        is_active: source?.isActive ?? true,
        is_visible_on_till: source?.isVisibleOnTill ?? true,
        show_in_report: d?.showInReport ?? true,
        default_vat_rate_id: source?.defaultVatRateId ?? '',
        department_id: target.kind === 'category' ? (c?.departmentId ?? target.departmentId) : '',
        parent_category_id: target.kind === 'category' ? (c?.parentId ?? target.parentId ?? '') : '',
        age_rule_default: c?.ageRuleDefault ?? 'none',
        negative_stock_mode: c?.negativeStockMode ?? '',
    };
}

/** Add or edit a department, category or sub-category. Keyed by the parent so it resets for each target. */
export function GroupDialog({ target, options, onClose }: { target: GroupTarget; options: CatalogueOptions; onClose: () => void }) {
    const { data, setData, transform, post, put, processing, errors } = useForm<GroupValues>(initial(target));
    const isDepartment = target.kind === 'department';
    const existing = isDepartment ? target.department : target.category;
    const parents = options.categories.filter((c) => c.parentId === null && c.departmentId === data.department_id && c.value !== existing?.id);
    const noun = isDepartment ? 'department' : data.parent_category_id ? 'sub-category' : 'category';

    transform((values) => {
        const blank = (v: string) => (v === '' ? null : v);
        const common = { name: values.name, colour_hex: values.colour_hex, is_active: values.is_active, is_visible_on_till: values.is_visible_on_till, default_vat_rate_id: blank(values.default_vat_rate_id) };

        return (
            isDepartment
                ? { ...common, show_in_report: values.show_in_report }
                : { ...common, department_id: values.department_id, parent_category_id: blank(values.parent_category_id), age_rule_default: values.age_rule_default, negative_stock_mode: blank(values.negative_stock_mode) }
        ) as unknown as GroupValues;
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: onClose };
        if (isDepartment) {
            if (existing) put(route('app.products.departments.update', existing.id), done);
            else post(route('app.products.departments.store'), done);
        } else if (existing) put(route('app.products.categories.update', existing.id), done);
        else post(route('app.products.categories.store'), done);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{existing ? `Edit ${existing.name}` : `Add a ${noun}`}</DialogTitle>
                        <DialogDescription>Every till gets this at its next sync.</DialogDescription>
                    </DialogHeader>

                    <FormField id="group-name" label="Name" error={errors.name}>
                        <Input id="group-name" autoFocus required maxLength={100} value={data.name} aria-invalid={!!errors.name} onChange={(e) => setData('name', e.target.value)} />
                    </FormField>

                    {!isDepartment && (
                        <FormGrid>
                            <FormField id="group-department" label="Department" error={errors.department_id}>
                                <OptionSelect id="group-department" value={data.department_id} options={options.departments} invalid={!!errors.department_id} onChange={(v) => { setData('department_id', v); setData('parent_category_id', ''); }} />
                            </FormField>
                            <FormField id="group-parent" label="Under" optional error={errors.parent_category_id}>
                                <OptionSelect id="group-parent" value={data.parent_category_id} options={parents} none="Top level" invalid={!!errors.parent_category_id} onChange={(v) => setData('parent_category_id', v)} />
                            </FormField>
                        </FormGrid>
                    )}

                    <FormGrid>
                        <FormField id="group-colour" label="Colour" error={errors.colour_hex}>
                            <div className="flex items-center gap-2">
                                <input
                                    type="color"
                                    aria-label="Pick a colour"
                                    className="border-input h-9 w-11 shrink-0 cursor-pointer rounded-md border bg-transparent p-1"
                                    value={/^#[0-9a-f]{6}$/i.test(data.colour_hex) ? data.colour_hex : '#1f6feb'}
                                    onChange={(e) => setData('colour_hex', e.target.value.toUpperCase())}
                                />
                                <Input id="group-colour" maxLength={7} className="font-mono uppercase" value={data.colour_hex} onChange={(e) => setData('colour_hex', e.target.value)} />
                            </div>
                        </FormField>
                        <FormField id="group-vat" label="Default VAT rate" optional help="For new products filed here." error={errors.default_vat_rate_id}>
                            <OptionSelect id="group-vat" value={data.default_vat_rate_id} options={options.vatRates} none="None" onChange={(v) => setData('default_vat_rate_id', v)} />
                        </FormField>
                        {!isDepartment && (
                            <FormField id="group-age" label="Default age check" error={errors.age_rule_default}>
                                <OptionSelect id="group-age" value={data.age_rule_default} options={options.ageRules} onChange={(v) => setData('age_rule_default', v)} />
                            </FormField>
                        )}
                    </FormGrid>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <CheckRow id="group-active" checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Active" />
                        <CheckRow id="group-till" checked={data.is_visible_on_till} onChange={(v) => setData('is_visible_on_till', v)} label="Shown on the till" />
                        {isDepartment && <CheckRow id="group-report" checked={data.show_in_report} onChange={(v) => setData('show_in_report', v)} label="Shown in reports" />}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                            {existing ? 'Save' : `Add ${noun}`}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
