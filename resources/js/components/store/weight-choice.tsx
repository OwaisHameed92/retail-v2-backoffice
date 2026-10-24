import { cn } from '@/lib/utils';
import { weightLabel, weightOptions } from './format';
import { type StoreProduct } from './types';

/**
 * Quick weights for a weighed product (500 g, 1 kg, 2 kg, within its limit): toggle buttons, 48px high. The
 * quantity stays the product's kg amount, so the basket and the server's 0.25 kg steps are unchanged.
 */
export function WeightChoice({
    product,
    value,
    onChange,
    disabled = false,
    className,
}: {
    product: StoreProduct;
    value: string;
    onChange: (kg: string) => void;
    disabled?: boolean;
    className?: string;
}) {
    const options = weightOptions(product);

    return (
        <div
            role="group"
            aria-label={`Weight of ${product.name}`}
            className={cn('grid gap-2', options.length >= 3 ? 'grid-cols-3' : 'grid-cols-2', className)}
        >
            {options.map((kg) => {
                const on = Number(kg) === Number(value);

                return (
                    <button
                        key={kg}
                        type="button"
                        aria-pressed={on}
                        disabled={disabled}
                        onClick={() => onChange(kg)}
                        className={cn(
                            'min-h-11 rounded-full px-2 text-sm font-semibold tabular-nums transition disabled:cursor-not-allowed disabled:opacity-50',
                            on ? 'bg-brand text-on-brand ring-brand ring-2' : 'text-ink ring-line hover:ring-brand-text bg-white ring-1',
                        )}
                    >
                        {weightLabel(kg)}
                    </button>
                );
            })}
        </div>
    );
}
