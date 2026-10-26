import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ToggleSwitch } from './toggle-switch';

interface ProductStockFieldsProps {
    outOfStock: boolean;
    stockCount: string;
    isWeighed: boolean;
    disabled: boolean;
    errors: Record<string, string | undefined>;
    onOutOfStockChange: (outOfStock: boolean) => void;
    onStockCountChange: (count: string) => void;
}

/**
 * OS-13: the shop's own stock for a business with no tills yet (online store only): "In stock" on or off and an
 * optional count. A completed online order takes its items off the count. Once a till is live, the tills' stock is
 * used instead and these are ignored.
 */
export function ProductStockFields({
    outOfStock,
    stockCount,
    isWeighed,
    disabled,
    errors,
    onOutOfStockChange,
    onStockCountChange,
}: ProductStockFieldsProps) {
    return (
        <div className="grid gap-3 rounded-lg border p-3">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <Label htmlFor="product-in-stock">In stock</Label>
                    <p className="text-muted-foreground text-xs leading-5">
                        Off shows the product as out of stock: customers see it but cannot order it.
                    </p>
                </div>
                <ToggleSwitch id="product-in-stock" checked={!outOfStock} disabled={disabled} onCheckedChange={(on) => onOutOfStockChange(!on)} />
            </div>
            {errors.out_of_stock && <p className="text-danger-foreground text-xs">{errors.out_of_stock}</p>}
            <div className="grid gap-2">
                <Label htmlFor="product-stock-count">{isWeighed ? 'Stock in kg (optional)' : 'Stock count (optional)'}</Label>
                <Input
                    id="product-stock-count"
                    type="number"
                    inputMode="decimal"
                    min={0}
                    step={isWeighed ? 0.25 : 1}
                    value={stockCount}
                    disabled={disabled || outOfStock}
                    className="max-w-40 tabular-nums"
                    aria-invalid={!!errors.stock_count}
                    aria-describedby="product-stock-count-help"
                    onChange={(e) => onStockCountChange(e.target.value)}
                />
                {errors.stock_count ? (
                    <p className="text-danger-foreground text-xs">{errors.stock_count}</p>
                ) : (
                    <p id="product-stock-count-help" className="text-muted-foreground text-xs leading-5">
                        Blank = not counted (always available while in stock). With a count, customers can order up to it, and each collected or
                        delivered order takes its items off. Your tills’ stock is used instead once a till is live.
                    </p>
                )}
            </div>
        </div>
    );
}
