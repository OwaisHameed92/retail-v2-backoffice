import { type Option, type PriceTierValues } from '@/components/app/pricing/types';
import { MoneyInput, NumberField } from '@/components/app/products/fields';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatMoneyWhole } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Plus, Trash2 } from 'lucide-react';

/** One line each, shown under the type name in the picker. */
export const TYPE_HELP: Record<string, string> = {
    percentOff: 'A percentage off each item.',
    fixedOff: 'An amount off each item.',
    fixedPrice: 'The item sells at this price.',
    get multiBuy() {
        return `A number of the same item for one price, e.g. 3 for ${formatMoneyWhole(2)}.`;
    },
    get quantityPrice() {
        return `Several prices by quantity, e.g. 2 for ${formatMoneyWhole(5)}, 3 for ${formatMoneyWhole(7)}.`;
    },
    bogof: 'Buy some, get some free.',
    buyGet: 'Buy some, get more at a discount.',
    mixMatch: 'Any of the chosen items, a number for one price.',
    mealDeal: 'One item from each group for one price.',
};

/** The offer types as cards: choosing one shows only the fields that type uses. */
export function TypePicker({
    value,
    options,
    onChange,
    invalid,
}: {
    value: string;
    options: Option[];
    onChange: (value: string) => void;
    invalid?: boolean;
}) {
    return (
        <div
            role="radiogroup"
            aria-label="Type of offer"
            aria-invalid={invalid || undefined}
            className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
        >
            {options.map((option) => {
                const selected = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'focus-visible:ring-ring rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60',
                            selected ? 'border-primary bg-primary/5 ring-primary ring-1' : 'hover:bg-muted/50',
                        )}
                    >
                        <span className="block text-sm font-medium">{option.label}</span>
                        <span className="text-muted-foreground mt-0.5 block text-xs leading-5">{TYPE_HELP[option.value] ?? 'An offer.'}</span>
                    </button>
                );
            })}
        </div>
    );
}

/** quantityPrice tiers: quantity → total price incl. VAT, smallest quantity first. */
export function PriceTiersEditor({
    tiers,
    onChange,
    error,
    disabled,
}: {
    tiers: PriceTierValues[];
    onChange: (tiers: PriceTierValues[]) => void;
    error?: string;
    disabled: boolean;
}) {
    const update = (index: number, patch: Partial<PriceTierValues>) => onChange(tiers.map((tier, i) => (i === index ? { ...tier, ...patch } : tier)));
    const next = () => {
        const last = Number(tiers[tiers.length - 1]?.quantity ?? 1);

        return String(Number.isFinite(last) ? Math.max(2, last + 1) : 2);
    };

    return (
        <div className="grid gap-3">
            <p className="text-muted-foreground text-sm">
                Each tier is a quantity and the total price for that many, including VAT. The till picks the tiers that save the customer most; any
                left over sell at the shelf price.
            </p>
            {error && (
                <p id="price_tiers-error" className="text-destructive text-sm">
                    {error}
                </p>
            )}
            <div className="grid gap-2">
                {tiers.map((tier, i) => (
                    <div key={i} className="grid grid-cols-[1fr_auto_1fr_auto] items-center gap-2 sm:max-w-md">
                        <NumberField
                            id={`price_tiers-${i}-quantity`}
                            aria-label={`Tier ${i + 1} quantity`}
                            inputMode="numeric"
                            suffix="items"
                            value={tier.quantity}
                            invalid={!!error}
                            onChange={(e) => update(i, { quantity: e.target.value })}
                        />
                        <span className="text-muted-foreground text-sm">for</span>
                        <MoneyInput
                            id={`price_tiers-${i}-price`}
                            aria-label={`Tier ${i + 1} price`}
                            value={tier.price}
                            invalid={!!error}
                            onChange={(e) => update(i, { price: e.target.value })}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            disabled={disabled}
                            aria-label={`Remove tier ${i + 1}`}
                            onClick={() => onChange(tiers.filter((_, x) => x !== i))}
                        >
                            <Trash2 />
                        </Button>
                    </div>
                ))}
            </div>
            {!disabled && (
                <Button
                    type="button"
                    variant="outline"
                    className="justify-self-start"
                    onClick={() => onChange([...tiers, { quantity: next(), price: '' }])}
                >
                    <Plus />
                    Add tier
                </Button>
            )}
        </div>
    );
}

/** The till's string: "2=5.00;3=7.00" (blank rows left out; the server checks the rest). */
export function tiersString(tiers: PriceTierValues[]): string {
    return tiers
        .filter((t) => t.quantity.trim() !== '' || t.price.trim() !== '')
        .map((t) => `${t.quantity.trim()}=${t.price.trim()}`)
        .join(';');
}

export const DAYS = [
    { value: 'monday', label: 'Mon' },
    { value: 'tuesday', label: 'Tue' },
    { value: 'wednesday', label: 'Wed' },
    { value: 'thursday', label: 'Thu' },
    { value: 'friday', label: 'Fri' },
    { value: 'saturday', label: 'Sat' },
    { value: 'sunday', label: 'Sun' },
];

/** Days the offer runs; none ticked is saved as every day. */
export function DaysPicker({ value, onChange, disabled }: { value: string[]; onChange: (days: string[]) => void; disabled?: boolean }) {
    const all = DAYS.map((d) => d.value);
    const preset = (days: string[]) => (
        <Button type="button" variant="link" size="sm" className="h-auto p-0" disabled={disabled} onClick={() => onChange(days)}>
            {days.length === 7 ? 'Every day' : days.length === 5 ? 'Weekdays' : 'Weekends'}
        </Button>
    );

    return (
        <div className="grid gap-2">
            <ToggleGroup
                type="multiple"
                variant="outline"
                aria-label="Days"
                value={value}
                disabled={disabled}
                onValueChange={(days) => onChange(all.filter((d) => days.includes(d)))}
                className="flex-wrap justify-start"
            >
                {DAYS.map((day) => (
                    <ToggleGroupItem key={day.value} value={day.value} aria-label={day.value} className="min-w-11">
                        {day.label}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
            <div className="flex gap-3 text-xs">
                {preset(all)}
                {preset(all.slice(0, 5))}
                {preset(all.slice(5))}
            </div>
        </div>
    );
}
