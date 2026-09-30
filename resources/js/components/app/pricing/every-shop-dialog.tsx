import { CheckField } from '@/components/app/setup/fields';
import { MoneyInput } from '@/components/app/products/fields';
import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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
    /** Shops ticked to lose their own price (the row the dialog was opened from). */
    preselect: string[];
}

/**
 * "Every shop": moves the business price and ends the own prices of the ticked shops (SHOP-OR-EVERY-SHOP.md).
 * Shops left unticked keep their own price, because a shop price always beats the business price.
 */
export function EveryShopDialog({ open, onOpenChange, productId, businessPrice, shops, preselect }: Props) {
    const withOwn = shops.filter((s) => s.current !== null);
    const form = useForm<{ price: string; end_shop_ids: string[] }>({ price: businessPrice, end_shop_ids: preselect });
    const { data, setData, processing, errors } = form;

    useEffect(() => {
        if (open) {
            setData({ price: businessPrice, end_shop_ids: preselect });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const toggle = (id: string, on: boolean) => setData('end_shop_ids', on ? [...data.end_shop_ids, id] : data.end_shop_ids.filter((x) => x !== id));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('app.prices.every-shop', productId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} noValidate className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Change the price for every shop</DialogTitle>
                        <DialogDescription>
                            Now {pounds(businessPrice)}. Every till gets the new business price at its next sync. Shops with their own price keep it unless you
                            tick them below.
                        </DialogDescription>
                    </DialogHeader>

                    <FormField id="every_price" label="New business price" error={errors.price}>
                        <MoneyInput id="every_price" value={data.price} invalid={!!errors.price} autoFocus onChange={(e) => setData('price', e.target.value)} />
                    </FormField>

                    {withOwn.length > 0 && (
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">Also end these shops' own prices</legend>
                            {withOwn.map((shop) => (
                                <CheckField
                                    key={shop.id}
                                    id={`end-${shop.id}`}
                                    label={`${shop.name} (now ${pounds(shop.current?.price)})`}
                                    help="Sells at the new business price from its next sync."
                                    checked={data.end_shop_ids.includes(shop.id)}
                                    onChange={(on) => toggle(shop.id, on)}
                                />
                            ))}
                            {errors.end_shop_ids && <p className="text-destructive text-sm">{errors.end_shop_ids}</p>}
                        </fieldset>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                            Change for every shop
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
