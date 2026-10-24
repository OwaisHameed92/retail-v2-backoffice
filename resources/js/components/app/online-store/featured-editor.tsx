import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { ArrowDown, ArrowUp, ImageIcon, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { ProductPicker } from './product-picker';
import { type BuilderProps, type FeaturedModeKey, type PickerProduct } from './types';

/**
 * The store builder's "Featured products" setting (OS-8): best sellers (from the tills' sales), hand-picked (a
 * searchable picker, up to 24, in order) or newest. Part of the builder form (saved with "Save changes").
 */
export function FeaturedEditor({
    props,
    mode,
    picked,
    onMode,
    onPicked,
    disabled,
    error,
}: {
    props: Pick<BuilderProps, 'featuredOptions' | 'featuredMax'>;
    mode: FeaturedModeKey;
    picked: PickerProduct[];
    onMode: (mode: FeaturedModeKey) => void;
    onPicked: (products: PickerProduct[]) => void;
    disabled: boolean;
    error?: string;
}) {
    const [picking, setPicking] = useState(false);
    const move = (index: number, by: -1 | 1) => {
        const next = [...picked];
        [next[index], next[index + by]] = [next[index + by], next[index]];
        onPicked(next);
    };

    return (
        <div className="grid gap-4">
            <div role="radiogroup" aria-label="Featured products" className="grid gap-2 sm:grid-cols-3">
                {props.featuredOptions.map((option) => {
                    const on = option.value === mode;

                    return (
                        <button
                            key={option.value}
                            type="button"
                            role="radio"
                            aria-checked={on}
                            disabled={disabled}
                            onClick={() => onMode(option.value)}
                            className={cn(
                                'focus-visible:ring-ring/40 flex flex-col gap-1 rounded-lg border p-3 text-left transition-colors outline-none focus-visible:ring-[3px] disabled:opacity-60',
                                on ? 'border-primary bg-primary-soft' : 'hover:border-border-strong',
                            )}
                        >
                            <span className={cn('text-sm font-medium', on && 'text-primary')}>{option.label}</span>
                            <span className="text-muted-foreground text-xs leading-5">{option.hint}</span>
                        </button>
                    );
                })}
            </div>

            {mode === 'handPicked' && (
                <div className="grid gap-3">
                    {picked.length === 0 ? (
                        <p className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">
                            No products chosen yet. Until you add some, the home page shows your newest products.
                        </p>
                    ) : (
                        <ol className="divide-border grid divide-y rounded-lg border">
                            {picked.map((product, i) => (
                                <li key={product.id} className="flex items-center gap-3 p-2 pl-3">
                                    <span className="text-muted-foreground w-5 text-xs tabular-nums">{i + 1}</span>
                                    <span className="bg-muted flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                                        {product.photoUrl ? (
                                            <img src={product.photoUrl} alt="" className="size-full object-contain" />
                                        ) : (
                                            <ImageIcon className="text-muted-foreground size-4" aria-hidden />
                                        )}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-sm">{product.name}</span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        disabled={disabled || i === 0}
                                        onClick={() => move(i, -1)}
                                        aria-label={`Move ${product.name} up`}
                                    >
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        disabled={disabled || i === picked.length - 1}
                                        onClick={() => move(i, 1)}
                                        aria-label={`Move ${product.name} down`}
                                    >
                                        <ArrowDown />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        disabled={disabled}
                                        onClick={() => onPicked(picked.filter((p) => p.id !== product.id))}
                                        aria-label={`Remove ${product.name}`}
                                    >
                                        <X />
                                    </Button>
                                </li>
                            ))}
                        </ol>
                    )}
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={disabled || picked.length >= props.featuredMax}
                            onClick={() => setPicking(true)}
                        >
                            <Plus />
                            Add products
                        </Button>
                        <span className="text-muted-foreground text-xs">
                            {picked.length} of {props.featuredMax}
                        </span>
                    </div>
                    <ProductPicker
                        open={picking}
                        onOpenChange={setPicking}
                        title="Add featured products"
                        chosen={picked.map((p) => p.id)}
                        onPick={(product) => {
                            const next = [...picked, product].slice(0, props.featuredMax);
                            onPicked(next);

                            if (next.length >= props.featuredMax) {
                                setPicking(false);
                            }
                        }}
                    />
                </div>
            )}
            {error && <p className="text-danger-foreground text-[13px]">{error}</p>}
        </div>
    );
}

/** The departments that get their own home row (OS-8, the "Rows by department" section), in the order chosen. */
export function HomeDepartmentsEditor({
    departments,
    value,
    max,
    onChange,
    disabled,
    error,
}: {
    departments: { id: string; name: string }[];
    value: string[];
    max: number;
    onChange: (ids: string[]) => void;
    disabled: boolean;
    error?: string;
}) {
    if (departments.length === 0) {
        return <p className="text-muted-foreground text-sm">Your tills have no departments yet.</p>;
    }

    return (
        <div className="grid gap-2">
            <ul className="flex flex-wrap gap-2" aria-label="Departments with a home row">
                {departments.map((d) => {
                    const at = value.indexOf(d.id);
                    const on = at >= 0;

                    return (
                        <li key={d.id}>
                            <button
                                type="button"
                                aria-pressed={on}
                                disabled={disabled || (!on && value.length >= max)}
                                onClick={() => onChange(on ? value.filter((id) => id !== d.id) : [...value, d.id])}
                                className={cn(
                                    'focus-visible:ring-ring/40 flex h-9 items-center gap-2 rounded-full border px-3 text-sm transition-colors outline-none focus-visible:ring-[3px] disabled:opacity-50',
                                    on ? 'border-primary bg-primary-soft text-primary font-medium' : 'hover:border-border-strong',
                                )}
                            >
                                {on && (
                                    <span className="bg-primary text-primary-foreground flex size-5 items-center justify-center rounded-full text-[11px]">
                                        {at + 1}
                                    </span>
                                )}
                                {d.name}
                            </button>
                        </li>
                    );
                })}
            </ul>
            <p className="text-muted-foreground text-xs">
                {value.length === 0
                    ? 'None chosen: the home page shows your first three departments.'
                    : `${value.length} of ${max} chosen, in this order.`}
            </p>
            {error && <p className="text-danger-foreground text-[13px]">{error}</p>}
        </div>
    );
}
