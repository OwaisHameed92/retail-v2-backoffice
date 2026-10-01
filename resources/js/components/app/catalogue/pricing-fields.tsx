import { NumberField, OptionSelect } from '@/components/app/products/fields';
import { FormField, FormGrid } from '@/components/shared/form-section';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type DepartmentCount, type PriceRuleValues, type YourDepartment } from './types';

const CREATE = '__create';

/** How the shop prices what it adds: RRP, or cost plus a margin (rounded to end in 9p). */
export function PriceRuleFields({
    value,
    onChange,
    errors,
}: {
    value: PriceRuleValues;
    onChange: (next: PriceRuleValues) => void;
    errors: Record<string, string>;
}) {
    const options = [
        { value: 'rrp', title: 'Recommended price (RRP)', body: 'The price on the catalogue. You can change any price later.' },
        { value: 'margin', title: 'Cost plus margin', body: 'From the cost you type for each product; RRP when there is no cost.' },
    ] as const;

    return (
        <div className="grid gap-4">
            <div role="radiogroup" aria-label="Price rule" className="grid gap-3 sm:grid-cols-2">
                {options.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={value.price_rule === option.value}
                        onClick={() => onChange({ ...value, price_rule: option.value })}
                        className={cn(
                            'hover:bg-subtle focus-visible:ring-ring/40 rounded-lg border p-3.5 text-left transition-colors outline-none focus-visible:ring-2',
                            value.price_rule === option.value && 'border-primary bg-primary-soft/40 ring-primary/20 ring-1',
                        )}
                    >
                        <span className="block text-sm font-medium">{option.title}</span>
                        <span className="text-muted-foreground mt-0.5 block text-sm">{option.body}</span>
                    </button>
                ))}
            </div>
            {value.price_rule === 'margin' && (
                <FormGrid>
                    <FormField id="margin" label="Margin" help="On the price before VAT." error={errors.margin}>
                        <NumberField
                            id="margin"
                            suffix="%"
                            value={value.margin}
                            invalid={!!errors.margin}
                            onChange={(e) => onChange({ ...value, margin: e.target.value })}
                        />
                    </FormField>
                    <div className="flex items-center gap-2 pt-7">
                        <Checkbox
                            id="end_in_9"
                            checked={value.end_in_9}
                            onCheckedChange={(state) => onChange({ ...value, end_in_9: state === true })}
                        />
                        <Label htmlFor="end_in_9" className="font-normal">
                            Round up to end in 9p
                        </Label>
                    </div>
                </FormGrid>
            )}
        </div>
    );
}

/** The business department each catalogue department goes into; one of the same name is used or created by default. */
export function DepartmentMapping({
    departments,
    yours,
    value,
    onChange,
    error,
}: {
    departments: DepartmentCount[];
    yours: YourDepartment[];
    value: Record<string, string>;
    onChange: (next: Record<string, string>) => void;
    error?: string;
}) {
    return (
        <div className="grid gap-3">
            {departments.map((department) => {
                const same = yours.find((d) => d.label.toLowerCase() === department.value.toLowerCase());
                const options = [
                    ...(same ? [] : [{ value: CREATE, label: `New department “${department.value}”` }]),
                    ...yours.map((d) => ({ value: d.value, label: d.label })),
                ];

                return (
                    <div key={department.value} className="grid items-center gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <p className="text-sm">
                            <span className="font-medium">{department.value}</span>{' '}
                            <span className="text-muted-foreground tabular-nums">({department.count})</span>
                        </p>
                        <OptionSelect
                            id={`map-${department.value}`}
                            value={value[department.value] || same?.value || CREATE}
                            options={options}
                            onChange={(next) => onChange({ ...value, [department.value]: next === CREATE ? '' : next })}
                        />
                    </div>
                );
            })}
            {error && <p className="text-danger-foreground text-[13px]">{error}</p>}
        </div>
    );
}

/** The mapping as the server takes it: only departments mapped to an existing one (others are matched or created by name). */
export function mappingPayload(value: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(value).filter(([, id]) => id !== ''));
}
