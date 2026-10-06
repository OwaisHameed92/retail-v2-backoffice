import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { currencySymbol, formatMoneyAsGiven, formatMoney as profileMoney, wideCurrencySymbol } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type ComponentProps, type ReactNode } from 'react';
import { type Option } from './types';

/** "1.4500" → "£1.45" (GB), "Rs 1" (PK); GB costs keep up to 4 decimal places when they have them. */
export function formatMoney(value: string | null | undefined, places: 2 | 4 = 2): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const number = Number(value);
    if (places === 4 && Math.round(number * 100) / 100 !== number) {
        return formatMoneyAsGiven(number.toFixed(4).replace(/0{1,2}$/, ''));
    }
    return profileMoney(number);
}

/** Gross margin on the sell price, after VAT: (net − cost) ÷ net. Null when it cannot be worked out. */
export function marginPercent(sell: string, cost: string, vatPercent: string | null | undefined): number | null {
    const net = Number(sell) / (1 + Number(vatPercent ?? 0) / 100);
    if (!Number.isFinite(net) || net <= 0 || cost === '' || !Number.isFinite(Number(cost))) {
        return null;
    }
    return Math.round(((net - Number(cost)) / net) * 1000) / 10;
}

/** Text input for money: the currency symbol as a prefix, the value kept as a string (the server checks the decimal places). */
export function MoneyInput({ id, invalid, className, places = 2, ...props }: ComponentProps<'input'> & { invalid?: boolean; places?: 2 | 4 }) {
    return (
        <div className="relative">
            <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm" aria-hidden>
                {currencySymbol()}
            </span>
            <Input
                id={id}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                pattern={places === 2 ? '^\\d{1,8}(\\.\\d{1,2})?$' : '^\\d{1,8}(\\.\\d{1,4})?$'}
                aria-invalid={invalid || undefined}
                aria-describedby={invalid ? `${id}-error` : `${id}-help`}
                className={cn(wideCurrencySymbol() ? 'pl-10' : 'pl-7', 'tabular-nums', className)}
                {...props}
            />
        </div>
    );
}

/** Decimal or whole-number text input with an optional unit after it ("ml", "%"). */
export function NumberField({ id, invalid, suffix, className, ...props }: ComponentProps<'input'> & { invalid?: boolean; suffix?: string }) {
    return (
        <div className="relative">
            <Input
                id={id}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                aria-invalid={invalid || undefined}
                aria-describedby={invalid ? `${id}-error` : `${id}-help`}
                className={cn('tabular-nums', suffix && 'pr-12', className)}
                {...props}
            />
            {suffix && (
                <span className="text-muted-foreground pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm" aria-hidden>
                    {suffix}
                </span>
            )}
        </div>
    );
}

/** A select over options; `none` adds an empty choice shown as that label. */
export function OptionSelect({
    id,
    value,
    onChange,
    options,
    placeholder = 'Choose…',
    none,
    invalid,
    disabled,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    options: Option[];
    placeholder?: string;
    none?: string;
    invalid?: boolean;
    disabled?: boolean;
}) {
    return (
        <Select
            value={value === '' ? (none ? '__none' : undefined) : value}
            onValueChange={(next) => onChange(next === '__none' ? '' : next)}
            disabled={disabled}
        >
            <SelectTrigger id={id} aria-invalid={invalid || undefined} aria-describedby={invalid ? `${id}-error` : undefined} className="w-full">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {none && <SelectItem value="__none">{none}</SelectItem>}
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Checkbox with a label and a helper line, for yes/no settings. */
export function CheckRow({
    id,
    checked,
    onChange,
    label,
    help,
    error,
    disabled,
    children,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    help?: ReactNode;
    error?: string;
    disabled?: boolean;
    children?: ReactNode;
}) {
    return (
        <div className={cn('rounded-lg border p-3.5 transition-colors', !disabled && 'hover:bg-subtle')}>
            <div className="flex items-start gap-3">
                <Checkbox
                    id={id}
                    checked={checked}
                    onCheckedChange={(state) => onChange(state === true)}
                    className="mt-0.5"
                    disabled={disabled}
                    aria-describedby={help ? `${id}-help` : undefined}
                />
                <div className="grid gap-0.5">
                    <label htmlFor={id} className="cursor-pointer text-sm font-medium">
                        {label}
                    </label>
                    {help && (
                        <p id={`${id}-help`} className="text-muted-foreground text-sm">
                            {help}
                        </p>
                    )}
                    {error && <p className="text-danger-foreground text-[13px]">{error}</p>}
                </div>
            </div>
            {checked && children && <div className="mt-3 grid gap-4 pl-7">{children}</div>}
        </div>
    );
}
