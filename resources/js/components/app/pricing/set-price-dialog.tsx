import { MoneyInput, OptionSelect } from '@/components/app/products/fields';
import { FormField, FormGrid } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useEffect } from 'react';
import { pounds } from './format';
import { type ProductPricesProps } from './types';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    productId: string;
    businessPrice: string;
    shops: ProductPricesProps['shops'];
    units: ProductPricesProps['units'];
    /** Pre-chosen shop (the row the dialog was opened from). */
    shopId: string | null;
}

/** A shop's own price: always saved as a new price row that only that shop's tills get (module 4.3). */
export function SetPriceDialog({ open, onOpenChange, productId, businessPrice, shops, units, shopId }: Props) {
    const form = useForm({ branch_id: shopId ?? '', product_unit_id: '', price: '', valid_from: '', valid_to: '' });
    const { data, setData, processing, errors, reset, clearErrors } = form;

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
            setData('branch_id', shopId ?? (shops.length === 1 ? shops[0].id : ''));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, shopId]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((values) => ({ ...values, product_unit_id: values.product_unit_id || null }));
        form.post(route('app.prices.shop.store', productId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} noValidate className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Set a shop price</DialogTitle>
                        <DialogDescription>
                            Business price {pounds(businessPrice)}. The shop charges this price instead, from when you choose, and its tills get it at their next sync.
                        </DialogDescription>
                    </DialogHeader>

                    <FormGrid>
                        <FormField id="branch_id" label="Shop" error={errors.branch_id}>
                            <OptionSelect
                                id="branch_id"
                                value={data.branch_id}
                                options={shops.map((s) => ({ value: s.id, label: s.name }))}
                                invalid={!!errors.branch_id}
                                disabled={shops.length === 1}
                                onChange={(value) => setData('branch_id', value)}
                            />
                        </FormField>
                        <FormField id="price" label="Price" error={errors.price}>
                            <MoneyInput id="price" value={data.price} invalid={!!errors.price} autoFocus onChange={(e) => setData('price', e.target.value)} />
                        </FormField>
                    </FormGrid>
                    {units.length > 0 && (
                        <FormField id="product_unit_id" label="Sold as" optional help="A pack or case has its own price." error={errors.product_unit_id}>
                            <OptionSelect
                                id="product_unit_id"
                                value={data.product_unit_id}
                                none="Single item (base unit)"
                                options={units.map((u) => ({ value: u.id, label: `${u.name} (${pounds(u.sellPrice)})` }))}
                                onChange={(value) => setData('product_unit_id', value)}
                            />
                        </FormField>
                    )}
                    <FormGrid>
                        <FormField id="valid_from" label="Starts" optional help="Empty = now. London time." error={errors.valid_from}>
                            <Input id="valid_from" type="datetime-local" value={data.valid_from} onChange={(e) => setData('valid_from', e.target.value)} />
                        </FormField>
                        <FormField id="valid_to" label="Ends" optional help="Empty = until changed." error={errors.valid_to}>
                            <Input id="valid_to" type="datetime-local" value={data.valid_to} onChange={(e) => setData('valid_to', e.target.value)} />
                        </FormField>
                    </FormGrid>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                            Save shop price
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
