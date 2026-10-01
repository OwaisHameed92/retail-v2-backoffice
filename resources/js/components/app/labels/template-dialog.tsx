import { OptionSelect } from '@/components/app/products/fields';
import { FormField, FormGrid } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useEffect, useMemo } from 'react';
import { LabelSheet } from './label-sheet';
import { type LabelContent, type LabelOptions, type LabelStock, type LabelTemplate, OPTION_LABELS } from './types';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template: LabelTemplate | null;
    stocks: LabelStock[];
    shop: { id: string; name: string };
    /** A one-shop user makes templates for their shop only. */
    shopOnly: boolean;
}

const SAMPLE: LabelContent = {
    id: 'sample',
    productId: 'sample',
    name: 'Coca-Cola Original 500ml',
    price: '1.25',
    priceText: '£1.25',
    unitPrice: '£2.50 per litre',
    barcode: null,
    offer: '2 for £2.00',
    offerUntil: null,
    pmp: null,
    deposit: '+ 20p deposit',
    shop: 'Your shop',
    date: '12/11/26',
    copies: 1,
};

/** A label template: the stock it prints on, what each label shows, and which shops use it. With a live sample label. */
export function TemplateDialog({ open, onOpenChange, template, stocks, shop, shopOnly }: Props) {
    const allOn = Object.fromEntries(OPTION_LABELS.map((o) => [o.key, true])) as LabelOptions;
    const form = useForm({ name: '', stock: 'a4_3x8', branch_id: shop.id as string | null, is_default: false as boolean, options: allOn });
    const { data, setData, processing, errors, reset, clearErrors } = form;

    useEffect(() => {
        if (open) {
            clearErrors();
            if (template) {
                setData({ name: template.name, stock: template.stock, branch_id: template.branchId, is_default: template.isDefault, options: template.options });
            } else {
                reset();
                setData('branch_id', shop.id);
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, template]);

    const stock = stocks.find((s) => s.key === data.stock) ?? stocks[0];
    const sample = useMemo(() => [{ ...SAMPLE, shop: shop.name }], [shop.name]);
    const preview = useMemo<LabelStock>(
        () => ({ ...stock, pageWidth: stock.width, pageHeight: stock.height, cols: 1, rows: 1, top: 0, left: 0, perPage: 1, kind: 'roll' }),
        [stock],
    );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        if (template) {
            form.put(route('app.labels.templates.update', template.id), options);
        } else {
            form.post(route('app.labels.templates.store'), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} noValidate className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{template ? 'Edit template' : 'New label template'}</DialogTitle>
                        <DialogDescription>Choose the label stock you buy and what each label shows.</DialogDescription>
                    </DialogHeader>

                    <FormGrid>
                        <FormField id="name" label="Name" error={errors.name}>
                            <Input id="name" value={data.name} maxLength={60} onChange={(e) => setData('name', e.target.value)} aria-invalid={Boolean(errors.name)} autoFocus />
                        </FormField>
                        <FormField id="stock" label="Label stock" error={errors.stock}>
                            <OptionSelect
                                id="stock"
                                value={data.stock}
                                options={stocks.map((s) => ({ value: s.key, label: s.ref ? `${s.name} · ${s.ref} size` : s.name }))}
                                onChange={(value) => setData('stock', value)}
                                invalid={Boolean(errors.stock)}
                            />
                        </FormField>
                    </FormGrid>

                    <FormGrid>
                        <FormField id="branch_id" label="Used by" error={errors.branch_id}>
                            <OptionSelect
                                id="branch_id"
                                value={data.branch_id ?? ''}
                                none={shopOnly ? undefined : 'Every shop'}
                                options={[{ value: shop.id, label: `${shop.name} only` }]}
                                onChange={(value) => setData('branch_id', value === '' ? null : value)}
                                disabled={shopOnly}
                            />
                        </FormField>
                        <div className="flex items-end gap-2 pb-2">
                            <Checkbox id="is_default" checked={data.is_default} onCheckedChange={(checked) => setData('is_default', checked === true)} />
                            <Label htmlFor="is_default" className="font-normal">
                                Use first when printing
                            </Label>
                        </div>
                    </FormGrid>

                    <div className="grid gap-5 sm:grid-cols-[1fr_14rem]">
                        <fieldset className="grid gap-3">
                            <legend className="mb-1 text-sm font-medium">Show on each label</legend>
                            {OPTION_LABELS.map((option) => (
                                <div key={option.key} className="flex items-start gap-2">
                                    <Checkbox
                                        id={`opt-${option.key}`}
                                        checked={data.options[option.key]}
                                        onCheckedChange={(checked) => setData('options', { ...data.options, [option.key]: checked === true })}
                                    />
                                    <Label htmlFor={`opt-${option.key}`} className="grid gap-0.5 leading-5 font-normal">
                                        <span>{option.label}</span>
                                        <span className="text-muted-foreground text-xs">{option.help}</span>
                                    </Label>
                                </div>
                            ))}
                        </fieldset>
                        <div className="grid content-start gap-2">
                            <span className="text-sm font-medium">Sample</span>
                            <div className="bg-muted/50 rounded-md border p-3">
                                <LabelSheet stock={preview} options={data.options} labels={sample} />
                            </div>
                            <span className="text-muted-foreground text-xs">
                                {stock.width} × {stock.height} mm{stock.kind === 'a4' ? `, ${stock.perPage} per A4 sheet` : ', one per roll label'}
                            </span>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                            Save template
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
