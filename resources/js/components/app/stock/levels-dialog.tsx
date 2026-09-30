import { DialogForm } from '@/components/admin/leads/dialog-form';
import { FormField } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';
import { type ProductStockProps } from './types';

type Data = { min_stock_qty: string; max_stock_qty: string; reorder_qty: string };

const plain = (value: string | null): string => (value === null ? '' : String(Number(value)));

/**
 * A product's own stock levels (Product is hub-owned, so every till gets them at its next sync). A shop's own reorder
 * point, min and max are set on its till and win over these.
 */
export function LevelsDialog({
    product,
    open,
    onOpenChange,
}: {
    product: ProductStockProps['product'];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<Data>({
        min_stock_qty: plain(product.minStockQty),
        max_stock_qty: plain(product.maxStockQty),
        reorder_qty: plain(product.reorderQty),
    });
    const { data, setData, errors, processing } = form;
    const minAboveMax = data.min_stock_qty !== '' && data.max_stock_qty !== '' && Number(data.max_stock_qty) < Number(data.min_stock_qty);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (minAboveMax) {
            return;
        }
        form.put(route('app.stock.products.levels', product.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const field = (key: keyof Data, label: string, help: string, error?: string) => (
        <FormField id={`levels-${key}`} label={label} optional help={help} error={error ?? errors[key]}>
            <Input
                id={`levels-${key}`}
                inputMode="decimal"
                value={data[key]}
                placeholder="Not set"
                onChange={(e) => setData(key, e.target.value.replace(/[^\d.]/g, ''))}
                aria-invalid={error || errors[key] ? true : undefined}
            />
        </FormField>
    );

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title="Stock levels"
            description={`For ${product.name} in every shop. A shop’s own reorder point, min and max (set on its till) come first.`}
            submitLabel="Save levels"
            processing={processing}
            disabled={minAboveMax}
            onSubmit={submit}
        >
            {field('min_stock_qty', 'Minimum', 'At or below this the product shows as low stock.')}
            {field('max_stock_qty', 'Most to hold', 'Orders top up to this.', minAboveMax ? 'Cannot be below the minimum.' : undefined)}
            {field('reorder_qty', 'Reorder quantity', 'How many to order at a time.')}
        </DialogForm>
    );
}
